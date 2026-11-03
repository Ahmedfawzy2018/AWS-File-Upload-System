<?php

declare(strict_types=1);

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class)->group('integration');

/**
 * Drain the shared events queue (other integration tests push events for their own keys)
 * until the file under test has become ready, with a bounded number of attempts.
 */
function consumeUntilReady(string $fileId, int $attempts = 8): void
{
    for ($i = 0; $i < $attempts; $i++) {
        test()->artisan('uploads:consume-s3-events', ['--once' => true])->assertSuccessful();
        if (File::findOrFail($fileId)->status->value === 'ready') {
            return;
        }
    }
}

it('single upload: initiate → PUT → event → ready → download', function () {
    actingAsUser();
    $init = $this->postJson('/api/v1/uploads', ['filename' => 'hi.txt', 'mime_type' => 'text/plain', 'size_bytes' => 5])
        ->assertCreated()->json();
    expect($init['upload']['type'])->toBe('single');

    Http::withHeaders($init['upload']['headers'])->withBody('hello', 'text/plain')->put($init['upload']['url'])->throw();

    consumeUntilReady($init['id']);

    $this->getJson("/api/v1/files/{$init['id']}")->assertOk()->assertJsonPath('status', 'ready');
    $url = $this->getJson("/api/v1/files/{$init['id']}/download")->assertOk()->json('url');
    expect(Http::get($url)->body())->toBe('hello');
});

it('multipart upload with a lowered threshold', function () {
    config(['uploads.multipart_threshold_bytes' => 6 * 1024 * 1024, 'uploads.part_size_bytes' => 5 * 1024 * 1024]);
    actingAsUser();

    $size = 6 * 1024 * 1024 + 1;
    $init = $this->postJson('/api/v1/uploads', ['filename' => 'big.txt', 'mime_type' => 'text/plain', 'size_bytes' => $size])
        ->assertCreated()->json();
    expect($init['upload']['type'])->toBe('multipart')
        ->and($init['upload']['parts'])->toHaveCount(2);

    $chunks = [str_repeat('a', 5 * 1024 * 1024), str_repeat('b', 1024 * 1024 + 1)];
    $parts = [];
    foreach ($init['upload']['parts'] as $i => $part) {
        $response = Http::withBody($chunks[$i], 'text/plain')->put($part['url'])->throw();
        $parts[] = ['part_number' => $part['part_number'], 'etag' => $response->header('ETag')];
    }

    $this->postJson("/api/v1/uploads/{$init['id']}/complete", ['parts' => $parts])
        ->assertStatus(202);

    // /complete answers 202 immediately; verification (inline under the sync test queue) makes it ready.
    $this->getJson("/api/v1/files/{$init['id']}")
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('size_bytes', $size);
});

it('rejects a lying client: PUT with a different content-type fails at S3', function () {
    actingAsUser();
    $init = $this->postJson('/api/v1/uploads', ['filename' => 'hi.txt', 'mime_type' => 'text/plain', 'size_bytes' => 5])->json();

    $headers = array_merge($init['upload']['headers'], ['Content-Type' => 'image/png']);
    $response = Http::withHeaders($headers)->withBody('hello', 'image/png')->put($init['upload']['url']);

    expect($response->status())->toBe(403);
    $this->getJson("/api/v1/files/{$init['id']}")->assertOk()->assertJsonPath('status', 'pending');
});

it('cannot overwrite a ready file with the original URL', function () {
    actingAsUser();
    $init = $this->postJson('/api/v1/uploads', ['filename' => 'hi.txt', 'mime_type' => 'text/plain', 'size_bytes' => 5])->json();
    $put = fn (string $body) => Http::withHeaders($init['upload']['headers'])->withBody($body, 'text/plain')->put($init['upload']['url']);

    $put('hello')->throw();
    $this->postJson("/api/v1/uploads/{$init['id']}/complete")->assertStatus(202);
    $this->getJson("/api/v1/files/{$init['id']}")->assertJsonPath('status', 'ready');

    expect($put('world')->status())->toBe(412);
});
