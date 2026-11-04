<?php

declare(strict_types=1);

use App\Domain\Files\Services\QuotaLedger;
use App\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

function errorCode(TestResponse $r): string
{
    return (string) $r->json('error.code');
}

it('initiates a single upload', function () {
    actingAsUser();
    $r = $this->postJson('/api/v1/uploads', ['filename' => 'a.png', 'mime_type' => 'image/png', 'size_bytes' => 1024])
        ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('upload.type', 'single')
        ->assertJsonPath('upload.method', 'PUT')->assertJsonStructure(['id', 'upload' => ['url', 'headers', 'expires_at']]);
    $file = File::find($r->json('id'));
    expect($file->object_key)->toMatch('#^users/\d+/\d{4}/\d{2}/[0-9A-Z]{26}\.png$#')
        ->and($file->object_key)->toContain($file->id)
        ->and($file->expires_at->diffInSeconds(now()->addSeconds(900), true))->toBeLessThan(5);
    expect(app(QuotaLedger::class)->usage($file->user_id)->reservedBytes)->toBe(1024);
});

it('uses single at exactly 100 MB and multipart above', function () {
    actingAsUser();
    $this->postJson('/api/v1/uploads', payload(size: 104857600))->assertJsonPath('upload.type', 'single');
    $this->postJson('/api/v1/uploads', payload(size: 104857601))->assertJsonPath('upload.type', 'multipart')
        ->assertJsonPath('upload.part_size', 16777216)->assertJsonCount(7, 'upload.parts');
});

it('returns the Content-Length each part URL is signed for', function () {
    actingAsUser();
    config(['uploads.multipart_threshold_bytes' => 16777216]);
    $parts = $this->postJson('/api/v1/uploads', payload(size: 16777216 * 2 + 7))->assertCreated()->json('upload.parts');

    expect($parts)->toHaveCount(3)
        ->and($parts[0])->toBe(['part_number' => 1, 'url' => $parts[0]['url'], 'headers' => ['Content-Length' => '16777216']])
        ->and($parts[1]['headers'])->toBe(['Content-Length' => '16777216'])
        ->and($parts[2]['headers'])->toBe(['Content-Length' => '7']);
});

it('creates the multipart upload outside the quota transaction', function () {
    actingAsUser();
    $level = DB::transactionLevel();

    $this->postJson('/api/v1/uploads', payload(size: 104857601))->assertCreated();

    expect(fakeS3()->createMultipartTransactionLevels())->toBe([$level]);
});

it('aborts the multipart upload when the quota reservation fails', function () {
    $user = actingAsUser();
    config(['uploads.default_quota_bytes' => 104857600]);

    $this->postJson('/api/v1/uploads', payload(size: 104857601))->assertStatus(409)->assertJsonPath('error.code', 'QUOTA_EXCEEDED');

    expect(fakeS3()->aborted())->toBe(['fake-upload-1'])
        ->and(File::count())->toBe(0)
        ->and(usageOf($user->id)->reservedBytes)->toBe(0);
});

it('stores a multipart upload id and 1h expiry', function () {
    actingAsUser();
    $r = $this->postJson('/api/v1/uploads', payload(size: 104857601))->assertCreated();
    $file = File::find($r->json('id'));
    expect($file->multipart_upload_id)->not->toBeNull()
        ->and($file->expires_at->diffInSeconds(now()->addSeconds(3600), true))->toBeLessThan(5);
});

it('rejects zero bytes', function () {
    actingAsUser();
    $this->postJson('/api/v1/uploads', payload(size: 0))->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['size_bytes']]]);
});

it('rejects over max size', function () {
    actingAsUser();
    $this->postJson('/api/v1/uploads', payload(size: 5368709121))->assertStatus(422)->assertJsonPath('error.code', 'FILE_TOO_LARGE');
});

it('accepts exactly max size', function () {
    actingAsUser();
    config(['uploads.default_quota_bytes' => 10737418240]);
    $this->postJson('/api/v1/uploads', payload(size: 5368709120))->assertCreated()->assertJsonPath('upload.type', 'multipart');
});

it('rejects disallowed mime', function () {
    actingAsUser();
    $this->postJson('/api/v1/uploads', payload(mime: 'application/x-msdownload'))->assertStatus(422)
        ->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
});

it('normalises mime params', function () {
    actingAsUser();
    $r = $this->postJson('/api/v1/uploads', payload(mime: 'Image/PNG; charset=x'))->assertCreated();
    expect(File::find($r->json('id'))->mime_type)->toBe('image/png');
});

it('rejects checksum on multipart', function () {
    actingAsUser();
    $this->postJson('/api/v1/uploads', payload(size: 104857601) + ['checksum_sha256' => str_repeat('a', 64)])
        ->assertStatus(422)->assertJsonPath('error.code', 'CHECKSUM_NOT_SUPPORTED');
});

it('accepts a checksum on single and lowercases it', function () {
    actingAsUser();
    $r = $this->postJson('/api/v1/uploads', payload() + ['checksum_sha256' => str_repeat('A', 64)])->assertCreated();
    expect(File::find($r->json('id'))->checksum_sha256)->toBe(str_repeat('a', 64));
});

it('validates checksum format', function () {
    actingAsUser();
    $this->postJson('/api/v1/uploads', payload() + ['checksum_sha256' => 'xyz'])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('rejects when quota exceeded and creates no row', function () {
    actingAsUser();
    config(['uploads.default_quota_bytes' => 1000]);
    $this->postJson('/api/v1/uploads', payload(size: 1001))->assertStatus(409)->assertJsonPath('error.code', 'QUOTA_EXCEEDED');
    expect(File::count())->toBe(0);
});

it('never puts the client filename in the key', function () {
    actingAsUser();
    $r = $this->postJson('/api/v1/uploads', ['filename' => '../../x.png', 'mime_type' => 'image/png', 'size_bytes' => 10])->assertCreated();
    $file = File::find($r->json('id'));
    expect($file->object_key)->not->toContain('..')->not->toContain('x.png')
        ->and($file->original_name)->not->toContain('/')->not->toContain('\\');
});

it('rate limits initiate per configured limit', function () {
    actingAsUser();
    config(['uploads.rate_limits.initiate' => 3]);
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/uploads', payload())->assertCreated();
    }
    $this->postJson('/api/v1/uploads', payload())->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});

it('requires auth', function () {
    $this->postJson('/api/v1/uploads', payload())->assertUnauthorized();
});
