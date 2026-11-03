<?php

use App\Domain\Files\Actions\ExpireStaleUploads;
use App\Domain\Files\Enums\FileStatus;
use App\Jobs\CleanupStaleUploadsJob;
use App\Models\File;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

function runCleanup(): int
{
    return app(ExpireStaleUploads::class)->handle(CarbonImmutable::now());
}

it('expires pending uploads past expiry plus grace and releases quota', function () {
    $file = pendingFile();
    $file->forceFill(['expires_at' => CarbonImmutable::now()->subMinutes(16)])->save();

    expect(runCleanup())->toBe(1);

    expect($file->fresh())->status->toBe(FileStatus::Expired)->failure_reason->toBe('ttl_expired');
    expect(usageOf($file->user_id))->reservedBytes->toBe(0)->usedBytes->toBe(0);
});

it('leaves uploads inside the grace window alone', function () {
    $file = pendingFile();
    $file->forceFill(['expires_at' => CarbonImmutable::now()->subMinutes(10)])->save();

    expect(runCleanup())->toBe(0);

    expect($file->fresh()->status)->toBe(FileStatus::Pending);
    expect(usageOf($file->user_id))->reservedBytes->toBe(5);
});

it('aborts unfinished multipart uploads', function () {
    $user = actingAsUser();
    $file = ownMultipartFile($user);
    $file->forceFill(['expires_at' => CarbonImmutable::now()->subHour()])->save();

    runCleanup();

    expect(fakeS3()->aborted())->toContain($file->multipart_upload_id);
    expect($file->fresh()->status)->toBe(FileStatus::Expired);
});

it('deletes a late object found at expiry', function () {
    $file = pendingFile();
    $file->forceFill(['expires_at' => CarbonImmutable::now()->subHour()])->save();
    fakeS3()->putObject($file->object_key, 5, 'text/plain', 'v7');

    runCleanup();

    expect(fakeS3()->deletedVersions())->toContain([$file->object_key, 'v7']);
    expect($file->fresh()->status)->toBe(FileStatus::Expired);
});

it('does not touch ready files', function () {
    $file = File::factory()->ready()->create(['expires_at' => CarbonImmutable::now()->subHour()]);

    expect(runCleanup())->toBe(0);

    expect($file->fresh()->status)->toBe(FileStatus::Ready);
});

it('skips a file whose lock is held and picks it up next run', function () {
    $file = pendingFile();
    $file->forceFill(['expires_at' => CarbonImmutable::now()->subHour()])->save();
    $lock = Cache::lock("files:{$file->id}:verify", 60);
    $lock->get();

    expect(runCleanup())->toBe(0);
    expect($file->fresh()->status)->toBe(FileStatus::Pending);

    $lock->release();
    expect(runCleanup())->toBe(1);
});

it('job delegates to the action', function () {
    $file = pendingFile();
    $file->forceFill(['expires_at' => CarbonImmutable::now()->subHour()])->save();

    (new CleanupStaleUploadsJob)->handle(app(ExpireStaleUploads::class));

    expect($file->fresh()->status)->toBe(FileStatus::Expired);
    expect((new CleanupStaleUploadsJob)->queue)->toBe('maintenance');
});

it('is scheduled every five minutes without overlap on one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->description, 'CleanupStaleUploadsJob'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('*/5 * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->onOneServer)->toBeTrue();
});
