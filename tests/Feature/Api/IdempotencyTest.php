<?php

declare(strict_types=1);

use App\Models\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

it('replays the original initiate response for a repeated key', function () {
    actingAsUser();
    $h = ['Idempotency-Key' => 'k1'];
    $a = $this->postJson('/api/v1/uploads', payload(), $h)->assertCreated();
    $b = $this->postJson('/api/v1/uploads', payload(), $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect($b->json('id'))->toBe($a->json('id'))->and(File::count())->toBe(1);
    expect($a->headers->has('Idempotent-Replayed'))->toBeFalse();
});

it('passes through without a key', function () {
    actingAsUser();
    $this->postJson('/api/v1/uploads', payload())->assertCreated();
    $this->postJson('/api/v1/uploads', payload())->assertCreated();
    expect(File::count())->toBe(2);
});

it('rejects key reuse with a different body', function () {
    actingAsUser();
    $h = ['Idempotency-Key' => 'k1'];
    $this->postJson('/api/v1/uploads', payload(), $h)->assertCreated();
    $this->postJson('/api/v1/uploads', payload(2048), $h)
        ->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    expect(File::count())->toBe(1);
});

it('treats key order in the body as the same body', function () {
    actingAsUser();
    $h = ['Idempotency-Key' => 'k1'];
    $p = payload();
    $this->postJson('/api/v1/uploads', $p, $h)->assertCreated();
    $this->postJson('/api/v1/uploads', array_reverse($p, true), $h)->assertHeader('Idempotent-Replayed', 'true');
});

it('scopes keys per user', function () {
    actingAsUser();
    $h = ['Idempotency-Key' => 'same'];
    $a = $this->postJson('/api/v1/uploads', payload(), $h)->assertCreated();
    actingAsUser();
    $b = $this->postJson('/api/v1/uploads', payload(), $h)->assertCreated();
    expect($b->headers->has('Idempotent-Replayed'))->toBeFalse()
        ->and($b->json('id'))->not->toBe($a->json('id'))->and(File::count())->toBe(2);
});

it('rejects a malformed key', function (string $key) {
    actingAsUser();
    $this->postJson('/api/v1/uploads', payload(), ['Idempotency-Key' => $key])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
})->with(['bad chars' => ['a b!'], 'too long' => [str_repeat('a', 129)]]);

it('replays stored 4xx responses', function () {
    actingAsUser();
    $h = ['Idempotency-Key' => 'k1'];
    $this->postJson('/api/v1/uploads', payload(size: 0), $h)->assertStatus(422);
    $this->postJson('/api/v1/uploads', payload(size: 0), $h)->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');
});

it('applies to complete too', function () {
    [, $file] = ownPendingFile();
    $h = ['Idempotency-Key' => 'c1'];
    $a = $this->postJson("/api/v1/uploads/{$file->id}/complete", [], $h);
    $b = $this->postJson("/api/v1/uploads/{$file->id}/complete", [], $h);
    $b->assertHeader('Idempotent-Replayed', 'true')->assertStatus($a->status());
    expect($b->json())->toBe($a->json());
});

it('returns 409 INVALID_STATE while the same key is in flight', function () {
    $user = actingAsUser();
    $lock = Cache::lock("idem:{$user->id}:uploads.store:k1:lock", 30);
    expect($lock->get())->toBeTrue();
    $this->postJson('/api/v1/uploads', payload(), ['Idempotency-Key' => 'k1'])
        ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE');
    expect(File::count())->toBe(0);
    $lock->release();
});

it('does not store 5xx responses', function () {
    $calls = 0;
    Route::middleware(['auth:sanctum', 'idempotent'])->post('/api/v1/test-boom', function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            throw new RuntimeException('boom');
        }

        return response()->json(['ok' => true], 201);
    })->name('test.boom');

    actingAsUser();
    $h = ['Idempotency-Key' => 'k1'];
    $this->postJson('/api/v1/test-boom', [], $h)->assertStatus(500);
    $r = $this->postJson('/api/v1/test-boom', [], $h)->assertCreated();
    expect($r->headers->has('Idempotent-Replayed'))->toBeFalse()->and($calls)->toBe(2);
});
