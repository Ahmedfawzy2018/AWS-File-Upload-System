<?php

use App\Domain\Files\Services\QuotaLedger;
use App\Models\File;

it('reports usage', function () {
    $user = actingAsUser();
    $ledger = app(QuotaLedger::class);
    $ledger->reserve($user->id, 5);
    $ledger->commit($user->id, 5);
    File::factory()->ready()->create(['user_id' => $user->id, 'size_bytes' => 5]);
    pendingFile(7, 'text/plain', $user);

    $this->getJson('/api/v1/me/usage')->assertOk()->assertExactJson([
        'used_bytes' => 5,
        'reserved_bytes' => 7,
        'quota_bytes' => 10737418240,
    ]);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/me/usage')->assertStatus(401);
});
