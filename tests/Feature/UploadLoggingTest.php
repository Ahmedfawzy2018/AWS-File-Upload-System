<?php

declare(strict_types=1);

use App\Domain\Files\Actions\ExpireStaleUploads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

it('logs upload.initiated with file id, user id and size', function () {
    $user = actingAsUser();
    Log::spy();

    $id = $this->postJson('/api/v1/uploads', payload(1024))->assertCreated()->json('id');

    Log::shouldHaveReceived('info')->with('upload.initiated', ['file_id' => $id, 'user_id' => $user->id, 'size_bytes' => 1024]);
});

it('logs upload.ready with user id and size', function () {
    $file = pendingFile(5);
    fakeS3()->putObject($file->object_key, 5, 'text/plain');
    Log::spy();

    runVerify($file);

    Log::shouldHaveReceived('info')->with('upload.ready', ['file_id' => $file->id, 'user_id' => $file->user_id, 'size_bytes' => 5]);
});

it('logs upload.rejected with user id and size', function () {
    $file = pendingFile(5);
    fakeS3()->putObject($file->object_key, 6, 'text/plain');
    Log::spy();

    runVerify($file);

    Log::shouldHaveReceived('warning')->with('upload.rejected', [
        'file_id' => $file->id, 'user_id' => $file->user_id, 'size_bytes' => 5, 'reason' => 'size_mismatch',
    ]);
});

it('logs upload.expired with user id and size on cleanup and on abort', function () {
    $stale = pendingFile(5);
    $stale->forceFill(['expires_at' => CarbonImmutable::now()->subHour()])->save();
    [$user, $aborted] = ownPendingFile();
    Log::spy();

    app(ExpireStaleUploads::class)->handle(CarbonImmutable::now());
    $this->postJson("/api/v1/uploads/{$aborted->id}/abort")->assertOk();

    Log::shouldHaveReceived('info')->with('upload.expired', [
        'file_id' => $stale->id, 'user_id' => $stale->user_id, 'size_bytes' => 5, 'reason' => 'ttl_expired',
    ]);
    Log::shouldHaveReceived('info')->with('upload.expired', [
        'file_id' => $aborted->id, 'user_id' => $user->id, 'size_bytes' => 5, 'reason' => 'aborted',
    ]);
});
