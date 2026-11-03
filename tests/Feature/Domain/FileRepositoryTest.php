<?php

use App\Domain\Files\Contracts\FileRepository;
use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Exceptions\InvalidState;
use App\Models\File;
use App\Models\User;
use Carbon\CarbonImmutable;

it('finds a file by object key', function () {
    $file = File::factory()->create();

    expect(app(FileRepository::class)->findByObjectKey($file->object_key)?->id)->toBe($file->id);
    expect(app(FileRepository::class)->findByObjectKey('nope'))->toBeNull();
});

it('finds soft-deleted files by object key', function () {
    $file = File::factory()->create(['status' => FileStatus::Deleted]);
    $file->delete();

    expect(app(FileRepository::class)->findByObjectKey($file->object_key)?->id)->toBe($file->id);
});

it('finds stale pending files only', function () {
    $stale = File::factory()->expired()->create();
    File::factory()->pending()->create(['expires_at' => now()->addHour()]);
    File::factory()->ready()->create(['expires_at' => now()->subHour()]);

    $found = app(FileRepository::class)->stalePending(CarbonImmutable::now(), 10);

    expect($found->pluck('id')->all())->toBe([$stale->id]);
});

it('limits stale results', function () {
    File::factory()->count(3)->expired()->create();

    expect(app(FileRepository::class)->stalePending(CarbonImmutable::now(), 2))->toHaveCount(2);
});

it('paginates own files newest first and filters by status', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $old = File::factory()->for($a)->ready()->create(['created_at' => now()->subDays(2)]);
    $new = File::factory()->for($a)->pending()->create(['created_at' => now()->subDay()]);
    File::factory()->for($b)->pending()->create();
    File::factory()->for($a)->ready()->create(['deleted_at' => now()]);

    $repo = app(FileRepository::class);

    expect(collect($repo->paginateForUser($a->id, null, 10)->items())->pluck('id')->all())
        ->toBe([$new->id, $old->id]);
    expect(collect($repo->paginateForUser($a->id, FileStatus::Ready, 10)->items())->pluck('id')->all())
        ->toBe([$old->id]);
});

it('sets timestamps on transition without saving', function () {
    $file = File::factory()->pending()->create();

    $file->transitionTo(FileStatus::Uploaded);
    expect($file->status)->toBe(FileStatus::Uploaded)->and($file->uploaded_at)->not->toBeNull();
    $file->transitionTo(FileStatus::Ready);
    expect($file->ready_at)->not->toBeNull();
    expect($file->fresh()->status)->toBe(FileStatus::Pending);

    expect(fn () => $file->transitionTo(FileStatus::Pending))->toThrow(InvalidState::class);
});
