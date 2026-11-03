<?php

use App\Domain\Files\Actions\VerifyUpload;
use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Events\FileReady;
use App\Domain\Files\Events\FileRejected;
use App\Domain\Files\Events\FileUploaded;
use App\Jobs\VerifyUploadJob;
use App\Models\File;
use Illuminate\Support\Facades\Event;

it('marks a matching object ready and commits quota', function () {
    $file = pendingFile(size: 5, mime: 'text/plain');
    fakeS3()->putObject($file->object_key, 5, 'text/plain', 'v9');
    Event::fake([FileUploaded::class, FileReady::class]);

    runVerify($file);

    expect($file->fresh())->status->toBe(FileStatus::Ready)->version_id->toBe('v9')->ready_at->not->toBeNull();
    expect($file->fresh()->etag)->not->toBeNull();
    expect(usageOf($file->user_id))->usedBytes->toBe(5)->reservedBytes->toBe(0);
    Event::assertDispatched(FileUploaded::class);
    Event::assertDispatched(FileReady::class);
});

it('accepts a content type carrying parameters', function () {
    $file = pendingFile(size: 5, mime: 'text/plain');
    fakeS3()->putObject($file->object_key, 5, 'text/plain; charset=utf-8');

    runVerify($file);

    expect($file->fresh()->status)->toBe(FileStatus::Ready);
});

it('leaves the file pending when the object is not there yet', function () {
    $file = pendingFile();

    runVerify($file);

    expect($file->fresh()->status)->toBe(FileStatus::Pending);
    expect(usageOf($file->user_id))->reservedBytes->toBe(5)->usedBytes->toBe(0);
});

it('rejects a size mismatch, deletes that version and releases quota', function () {
    $file = pendingFile(size: 5);
    fakeS3()->putObject($file->object_key, 6, 'text/plain', 'v1');
    Event::fake([FileRejected::class]);

    runVerify($file);

    expect($file->fresh())->status->toBe(FileStatus::Rejected)->failure_reason->toBe('size_mismatch');
    expect(fakeS3()->deletedVersions())->toContain([$file->object_key, 'v1']);
    expect(usageOf($file->user_id))->reservedBytes->toBe(0)->usedBytes->toBe(0);
    Event::assertDispatched(FileRejected::class, fn ($e) => $e->fileId === $file->id && $e->reason === 'size_mismatch');
});

it('rejects a content-type mismatch', function () {
    $file = pendingFile(size: 5, mime: 'text/plain');
    fakeS3()->putObject($file->object_key, 5, 'image/png', 'v2');

    runVerify($file);

    expect($file->fresh())->status->toBe(FileStatus::Rejected)->failure_reason->toBe('content_type_mismatch');
    expect(fakeS3()->deletedVersions())->toContain([$file->object_key, 'v2']);
    expect(usageOf($file->user_id)->reservedBytes)->toBe(0);
});

it('deletes the plain object when the rejected object has no version id', function () {
    $file = pendingFile(size: 5);
    fakeS3()->putObject($file->object_key, 9, 'text/plain', null);

    runVerify($file);

    expect($file->fresh()->status)->toBe(FileStatus::Rejected);
    expect(fakeS3()->deletedVersions())->toBe([]);
    expect(fakeS3()->objects())->not->toHaveKey($file->object_key);
});

it('permanently deletes the object when an abort expired the file after HEAD', function () {
    $file = pendingFile(size: 5, mime: 'text/plain');
    fakeS3()->putObject($file->object_key, 5, 'text/plain', 'v3');
    fakeS3()->afterNextHead(fn () => File::whereKey($file->id)->update(['status' => FileStatus::Expired->value]));
    $before = usageOf($file->user_id);

    runVerify($file);

    expect($file->fresh()->status)->toBe(FileStatus::Expired)
        ->and(fakeS3()->objects())->not->toHaveKey($file->object_key)
        ->and(fakeS3()->deletedVersions())->toBe([[$file->object_key, 'v3']])
        ->and(usageOf($file->user_id))->toEqual($before);
});

it('is a no-op when run twice', function () {
    $file = pendingFile(size: 5);
    fakeS3()->putObject($file->object_key, 5, 'text/plain');
    Event::fake([FileUploaded::class, FileReady::class]);

    runVerify($file);
    runVerify($file);

    expect($file->fresh()->status)->toBe(FileStatus::Ready);
    expect(usageOf($file->user_id))->usedBytes->toBe(5)->reservedBytes->toBe(0);
    Event::assertDispatchedTimes(FileReady::class, 1);
});

it('ignores a file id that no longer exists', function () {
    $storage = Mockery::mock(StorageGateway::class);
    $storage->shouldNotReceive('head', 'delete', 'deleteVersion');
    app()->instance(StorageGateway::class, $storage);

    expect(fn () => (new VerifyUploadJob('01hznonexistent'))->handle(app(VerifyUpload::class)))->not->toThrow(Throwable::class)
        ->and(File::count())->toBe(0);
});

it('is queued on the uploads queue with retries', function () {
    $job = new VerifyUploadJob('x');

    expect($job->queue)->toBe('uploads')->and($job->tries)->toBe(3)->and($job->backoff)->toBe([5, 30, 120]);
});
