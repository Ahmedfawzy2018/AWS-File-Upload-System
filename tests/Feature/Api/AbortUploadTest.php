<?php

use App\Domain\Files\Enums\FileStatus;
use App\Jobs\DeleteObjectJob;
use App\Models\File;
use Illuminate\Support\Facades\Queue;

it('aborts a pending multipart upload and releases quota', function () {
    $user = actingAsUser();
    $file = ownMultipartFile($user, 3);
    expect(usageOf($user->id)->reservedBytes)->toBe($file->size_bytes);

    $this->postJson("/api/v1/uploads/{$file->id}/abort")
        ->assertOk()
        ->assertJsonPath('status', 'expired')
        ->assertJsonPath('failure_reason', 'aborted');

    expect(fakeS3()->aborted())->toBe([$file->multipart_upload_id]);
    expect($file->fresh()->status)->toBe(FileStatus::Expired);
    expect(usageOf($user->id)->reservedBytes)->toBe(0);
});

it('aborts a single upload without calling S3', function () {
    [$user, $file] = ownPendingFile();

    $this->postJson("/api/v1/uploads/{$file->id}/abort")->assertOk()->assertJsonPath('status', 'expired');

    expect(fakeS3()->aborted())->toBe([]);
    expect(usageOf($user->id)->reservedBytes)->toBe(0);
});

it('refuses to abort a ready file', function () {
    $user = actingAsUser();
    $file = File::factory()->ready()->create(['user_id' => $user->id]);

    $this->postJson("/api/v1/uploads/{$file->id}/abort")
        ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE');
});

it('returns 404 for another user\'s upload', function () {
    $other = pendingFile();
    actingAsUser();

    $this->postJson("/api/v1/uploads/{$other->id}/abort")->assertStatus(404);
});

it('permanently deletes a single-PUT object that landed before the abort won the race', function () {
    [$user, $file] = ownPendingFile();
    fakeS3()->putObject($file->object_key, 5, 'text/plain', 'v1');

    $this->postJson("/api/v1/uploads/{$file->id}/abort")->assertOk()->assertJsonPath('status', 'expired');

    expect(fakeS3()->objects())->not->toHaveKey($file->object_key)
        ->and(fakeS3()->deletedVersions())->toBe([[$file->object_key, 'v1']])
        ->and(usageOf($user->id))->reservedBytes->toBe(0)->usedBytes->toBe(0);
});

it('permanently deletes the assembled object of a completed multipart upload on abort', function () {
    $user = actingAsUser();
    $file = ownMultipartFile($user, 2);
    fakeS3()->completeMultipart($file->objectKey(), (string) $file->multipart_upload_id, [1 => '"a"', 2 => '"b"']);
    $file->multipart_completed_at = now();
    $file->save();

    $this->postJson("/api/v1/uploads/{$file->id}/abort")->assertOk()->assertJsonPath('status', 'expired');

    expect(fakeS3()->objects())->not->toHaveKey($file->object_key)
        ->and(fakeS3()->aborted())->toBe([]);
});

it('does not queue an object delete for a multipart upload that was never completed', function () {
    Queue::fake();
    $user = actingAsUser();
    $file = ownMultipartFile($user, 2);

    $this->postJson("/api/v1/uploads/{$file->id}/abort")->assertOk();

    Queue::assertNotPushed(DeleteObjectJob::class);
});

it('queues the permanent delete only after the abort commits', function () {
    Queue::fake();
    [, $file] = ownPendingFile();

    $this->postJson("/api/v1/uploads/{$file->id}/abort")->assertOk();

    Queue::assertPushed(DeleteObjectJob::class, fn (DeleteObjectJob $j) => $j->objectKey === $file->object_key
        && $j->permanent === true
        && $j->afterCommit === true);
});
