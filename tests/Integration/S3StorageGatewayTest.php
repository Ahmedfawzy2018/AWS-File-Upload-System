<?php

declare(strict_types=1);

use App\Domain\Files\Exceptions\InvalidParts;
use App\Domain\Files\ValueObjects\FileSize;
use App\Domain\Files\ValueObjects\MimeType;
use Illuminate\Support\Facades\Http;

uses()->group('integration');

function putTo(object $req, string $body, string $type = 'text/plain')
{
    return Http::withHeaders($req->headers)->withBody($body, $type)->put($req->url);
}

it('presigned PUT uploads, HEAD reports metadata', function () {
    $req = gateway()->presignPut($key = uniqueKey(), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900);
    Http::withHeaders($req->headers)->withBody('hello', 'text/plain')->put($req->url)->throw();

    expect(gateway()->head($key))->sizeBytes->toBe(5)->contentType->toBe('text/plain')->versionId->not->toBeNull();
});

it('signed headers include content-type, content-length and if-none-match', function () {
    $req = gateway()->presignPut(uniqueKey(), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900);
    expect(array_change_key_case($req->headers))->toHaveKeys(['content-type', 'content-length', 'if-none-match']);
    expect($req->method)->toBe('PUT');
});

it('includes the checksum header when a sha256 is given', function () {
    $req = gateway()->presignPut(uniqueKey(), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900, hash('sha256', 'hello'));
    expect($req->headers)->toHaveKey('x-amz-checksum-sha256', base64_encode(hash('sha256', 'hello', true)));
    Http::withHeaders($req->headers)->withBody('hello', 'text/plain')->put($req->url)->throw();
});

it('rejects a second PUT to the same key', function () {
    $req = gateway()->presignPut(uniqueKey(), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900);
    putTo($req, 'hello')->throw();

    expect(putTo($req, 'hello')->status())->toBe(412);
});

it('rejects a PUT whose body length differs from the signed size', function () {
    $req = gateway()->presignPut(uniqueKey(), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900);
    $headers = $req->headers;
    $headers['Content-Length'] = '6';

    $status = Http::withHeaders($headers)->withBody('hello!', 'text/plain')->put($req->url)->status();
    expect($status)->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
});

it('rejects a PUT with a different content type than signed', function () {
    $req = gateway()->presignPut(uniqueKey(), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900);
    $headers = $req->headers;
    $headers['Content-Type'] = 'image/png';

    expect(Http::withHeaders($headers)->withBody('hello', 'image/png')->put($req->url)->status())->toBeGreaterThanOrEqual(400);
});

it('rejects a PUT that drops the signed If-None-Match header', function () {
    $req = gateway()->presignPut(uniqueKey(), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900);
    $headers = $req->headers;
    unset($headers['If-None-Match']);

    expect(Http::withHeaders($headers)->withBody('hello', 'text/plain')->put($req->url)->status())->toBe(403);
});

it('head returns null for a missing key', fn () => expect(gateway()->head(uniqueKey()))->toBeNull());

it('multipart: create, presign parts, complete', function () {
    $key = uniqueKey();
    $uploadId = gateway()->createMultipart($key, MimeType::fromString('text/plain'));
    $bodies = [1 => str_repeat('a', 5 * 1024 * 1024), 2 => 'b'];
    $etags = [];
    foreach ($bodies as $n => $body) {
        $req = gateway()->presignPart($key, $uploadId, $n, strlen($body), 3600);
        expect(array_change_key_case($req->headers))->not->toHaveKey('content-type')
            ->and($req->headers)->toBe(['Content-Length' => (string) strlen($body)]);
        $res = Http::withHeaders($req->headers)->withBody($body, 'application/octet-stream')->put($req->url)->throw();
        $etags[$n] = $res->header('ETag');
    }

    gateway()->completeMultipart($key, $uploadId, $etags);

    expect(gateway()->head($key)->sizeBytes)->toBe(5 * 1024 * 1024 + 1);
});

it('rejects a part body whose length differs from the signed part size', function () {
    $key = uniqueKey();
    $uploadId = gateway()->createMultipart($key, MimeType::fromString('text/plain'));
    $req = gateway()->presignPart($key, $uploadId, 1, 5, 3600);
    $headers = $req->headers;
    $headers['Content-Length'] = '6';

    $status = Http::withHeaders($headers)->withBody('hello!', 'application/octet-stream')->put($req->url)->status();
    expect($status)->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
});

it('completeMultipart with a bogus etag throws InvalidParts', function () {
    $key = uniqueKey();
    $uploadId = gateway()->createMultipart($key, MimeType::fromString('text/plain'));
    $req = gateway()->presignPart($key, $uploadId, 1, 1, 3600);
    Http::withHeaders($req->headers)->withBody('x', 'application/octet-stream')->put($req->url)->throw();

    gateway()->completeMultipart($key, $uploadId, [1 => '"deadbeefdeadbeefdeadbeefdeadbeef"']);
})->throws(InvalidParts::class);

it('abortMultipart makes the upload id unusable', function () {
    $key = uniqueKey();
    $uploadId = gateway()->createMultipart($key, MimeType::fromString('text/plain'));
    gateway()->abortMultipart($key, $uploadId);

    gateway()->completeMultipart($key, $uploadId, [1 => '"x"']);
})->throws(InvalidParts::class);

it('presignGet sets content-disposition with the original name', function () {
    $key = uniqueKey();
    $req = gateway()->presignPut($key, MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900);
    putTo($req, 'hello')->throw();

    $get = gateway()->presignGet($key, 'report.pdf', 300);
    $res = Http::get($get->url)->throw();

    expect($get->method)->toBe('GET')
        ->and($res->header('Content-Disposition'))->toContain('filename="report.pdf"')
        ->and($res->body())->toBe('hello');
});

it('presignGet escapes non-ascii names', function () {
    $key = uniqueKey();
    putTo(gateway()->presignPut($key, MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900), 'hello')->throw();

    $res = Http::get(gateway()->presignGet($key, 'résumé "x".pdf', 300)->url)->throw();

    expect($res->header('Content-Disposition'))->toContain("filename*=UTF-8''r%C3%A9sum%C3%A9%20%22x%22.pdf");
});

it('deleteVersion removes the version permanently', function () {
    $key = uniqueKey();
    putTo(gateway()->presignPut($key, MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900), 'hello')->throw();
    $versionId = gateway()->head($key)->versionId;

    gateway()->deleteVersion($key, $versionId);

    expect(gateway()->head($key))->toBeNull();
});

it('delete leaves a delete marker so head is null', function () {
    $key = uniqueKey();
    putTo(gateway()->presignPut($key, MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900), 'hello')->throw();

    gateway()->delete($key);

    expect(gateway()->head($key))->toBeNull();
});
