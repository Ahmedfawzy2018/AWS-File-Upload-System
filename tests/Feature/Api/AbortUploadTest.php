<?php

use App\Domain\Files\Enums\FileStatus;
use App\Models\File;

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
