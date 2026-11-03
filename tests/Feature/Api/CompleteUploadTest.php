<?php

use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Exceptions\InvalidParts;
use App\Jobs\VerifyUploadJob;
use App\Models\File;
use Illuminate\Support\Facades\Queue;

function partsBody(array $parts): array
{
    $out = [];
    foreach ($parts as $number => $etag) {
        $out[] = ['part_number' => $number, 'etag' => $etag];
    }

    return ['parts' => $out];
}

it('dispatches verification for a single upload and returns 202 pending', function () {
    Queue::fake();
    [, $file] = ownPendingFile();

    $this->postJson("/api/v1/uploads/{$file->id}/complete")
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending');

    Queue::assertPushedOn('uploads', VerifyUploadJob::class);
});

it('returns 404 for another user\'s upload', function () {
    Queue::fake();
    $other = pendingFile();
    actingAsUser();

    $this->postJson("/api/v1/uploads/{$other->id}/complete")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
    Queue::assertNothingPushed();
});

it('returns current state without work when already ready', function () {
    Queue::fake();
    $user = actingAsUser();
    $file = File::factory()->ready()->create(['user_id' => $user->id]);

    $this->postJson("/api/v1/uploads/{$file->id}/complete")
        ->assertStatus(202)
        ->assertJsonPath('status', 'ready');
    Queue::assertNothingPushed();
});

it('returns current state without work when already uploaded', function () {
    Queue::fake();
    $user = actingAsUser();
    $file = File::factory()->create(['user_id' => $user->id, 'status' => FileStatus::Uploaded]);

    $this->postJson("/api/v1/uploads/{$file->id}/complete")->assertStatus(202)->assertJsonPath('status', 'uploaded');
    Queue::assertNothingPushed();
});

it('returns 409 INVALID_STATE for expired, rejected and deleted uploads', function (FileStatus $status) {
    Queue::fake();
    $user = actingAsUser();
    $file = File::factory()->create(['user_id' => $user->id, 'status' => $status]);

    $this->postJson("/api/v1/uploads/{$file->id}/complete")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE');
    Queue::assertNothingPushed();
})->with([FileStatus::Expired, FileStatus::Rejected, FileStatus::Deleted]);

it('requires authentication', function () {
    $file = pendingFile();
    $this->postJson("/api/v1/uploads/{$file->id}/complete")->assertStatus(401);
});

it('completes multipart with the provided parts', function () {
    Queue::fake();
    $user = actingAsUser();
    $file = ownMultipartFile($user, 3);

    $this->postJson("/api/v1/uploads/{$file->id}/complete", partsBody([1 => 'e1', 2 => 'e2', 3 => 'e3']))
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending');

    expect(fakeS3()->completedUploads())->toBe([$file->multipart_upload_id]);
    expect($file->fresh()->multipart_completed_at)->not->toBeNull();
    Queue::assertPushedOn('uploads', VerifyUploadJob::class);
});

it('requires parts for multipart', function () {
    Queue::fake();
    $user = actingAsUser();
    $file = ownMultipartFile($user, 3);

    $this->postJson("/api/v1/uploads/{$file->id}/complete")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_PARTS');
    expect(fakeS3()->completedUploads())->toBe([]);
    Queue::assertNothingPushed();
});

it('rejects missing, duplicate or out-of-range parts with 422 INVALID_PARTS', function (array $parts) {
    Queue::fake();
    $user = actingAsUser();
    $file = ownMultipartFile($user, 3);

    $body = isset($parts['raw']) ? ['parts' => $parts['raw']] : partsBody($parts);
    $this->postJson("/api/v1/uploads/{$file->id}/complete", $body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_PARTS');

    expect($file->fresh()->status)->toBe(FileStatus::Pending);
    expect($file->fresh()->multipart_completed_at)->toBeNull();
    expect(fakeS3()->completedUploads())->toBe([]);
    Queue::assertNothingPushed();
})->with([
    'missing' => [[1 => 'a', 2 => 'b']],
    'extra' => [[1 => 'a', 2 => 'b', 3 => 'c', 4 => 'd']],
    'out of range' => [[1 => 'a', 2 => 'b', 4 => 'c']],
    'zero' => [[0 => 'a', 1 => 'b', 2 => 'c']],
    'duplicate' => [['raw' => [
        ['part_number' => 1, 'etag' => 'a'], ['part_number' => 1, 'etag' => 'b'],
        ['part_number' => 2, 'etag' => 'c'], ['part_number' => 3, 'etag' => 'd'],
    ]]],
]);

it('surfaces S3 InvalidPart as 422 and allows a corrected retry', function () {
    Queue::fake();
    $user = actingAsUser();
    $file = ownMultipartFile($user, 3);
    $body = partsBody([1 => 'e1', 2 => 'e2', 3 => 'bad']);

    fakeS3()->failCompleteWith(new InvalidParts('InvalidPart'));
    $this->postJson("/api/v1/uploads/{$file->id}/complete", $body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_PARTS');
    expect($file->fresh()->status)->toBe(FileStatus::Pending);
    expect($file->fresh()->multipart_completed_at)->toBeNull();
    Queue::assertNothingPushed();

    $this->postJson("/api/v1/uploads/{$file->id}/complete", partsBody([1 => 'e1', 2 => 'e2', 3 => 'e3']))
        ->assertStatus(202);
    expect($file->fresh()->multipart_completed_at)->not->toBeNull();
    Queue::assertPushedOn('uploads', VerifyUploadJob::class);
});

it('does not call S3 complete twice', function () {
    Queue::fake();
    $user = actingAsUser();
    $file = ownMultipartFile($user, 3);
    $body = partsBody([1 => 'e1', 2 => 'e2', 3 => 'e3']);

    $this->postJson("/api/v1/uploads/{$file->id}/complete", $body)->assertStatus(202);
    $this->postJson("/api/v1/uploads/{$file->id}/complete", $body)->assertStatus(202);

    expect(fakeS3()->completedUploads())->toHaveCount(1);
});
