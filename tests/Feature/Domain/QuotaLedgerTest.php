<?php

use App\Domain\Files\DTOs\StorageUsage;
use App\Domain\Files\Exceptions\QuotaExceeded;
use App\Domain\Files\Services\QuotaLedger;
use App\Models\User;

beforeEach(function () {
    config(['uploads.default_quota_bytes' => 100]);
});

it('reserves within quota and reports usage', function () {
    $u = User::factory()->create();
    app(QuotaLedger::class)->reserve($u->id, 60);

    expect(app(QuotaLedger::class)->usage($u->id))->toEqual(new StorageUsage(0, 60, 100));
});

it('rejects a second reservation that would exceed quota', function () {
    $u = User::factory()->create();
    $l = app(QuotaLedger::class);
    $l->reserve($u->id, 60);

    expect(fn () => $l->reserve($u->id, 41))->toThrow(QuotaExceeded::class);
    expect($l->usage($u->id)->reservedBytes)->toBe(60);
});

it('reports remaining bytes when quota is exceeded', function () {
    $u = User::factory()->create();
    $l = app(QuotaLedger::class);
    $l->reserve($u->id, 60);

    try {
        $l->reserve($u->id, 41);
        $this->fail('expected QuotaExceeded');
    } catch (QuotaExceeded $e) {
        expect($e->details())->toBe(['remaining_bytes' => 40]);
    }
});

it('allows filling quota exactly', function () {
    $u = User::factory()->create();
    $l = app(QuotaLedger::class);
    $l->reserve($u->id, 60);
    $l->reserve($u->id, 40);

    expect($l->usage($u->id))->toEqual(new StorageUsage(0, 100, 100));
});

it('commits reserved to used and releases', function () {
    $u = User::factory()->create();
    $l = app(QuotaLedger::class);

    $l->reserve($u->id, 50);
    $l->commit($u->id, 50);
    expect($l->usage($u->id))->toEqual(new StorageUsage(50, 0, 100));

    $l->reserve($u->id, 10);
    $l->release($u->id, 10);
    expect($l->usage($u->id))->toEqual(new StorageUsage(50, 0, 100));

    $l->releaseUsed($u->id, 50);
    expect($l->usage($u->id))->toEqual(new StorageUsage(0, 0, 100));
});

it('never goes negative on release', function () {
    $u = User::factory()->create();
    $l = app(QuotaLedger::class);

    $l->release($u->id, 10);
    $l->releaseUsed($u->id, 10);

    expect($l->usage($u->id))->toEqual(new StorageUsage(0, 0, 100));
});

it('reports default usage without inserting a row', function () {
    $u = User::factory()->create();

    expect(app(QuotaLedger::class)->usage($u->id))->toEqual(new StorageUsage(0, 0, 100));
    $this->assertDatabaseMissing('user_storage_usage', ['user_id' => $u->id]);
});
