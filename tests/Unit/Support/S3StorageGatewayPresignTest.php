<?php

declare(strict_types=1);

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\ValueObjects\FileSize;
use App\Domain\Files\ValueObjects\MimeType;
use App\Domain\Files\ValueObjects\ObjectKey;

function signedHeaders(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return $q['X-Amz-SignedHeaders'];
}

it('binds content-length, content-type and if-none-match into the presigned PUT signature', function () {
    config(['filesystems.disks.s3.key' => 'k', 'filesystems.disks.s3.secret' => 's']);
    $req = app(StorageGateway::class)->presignPut(ObjectKey::fromString('users/1/a.txt'), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900);

    expect(signedHeaders($req->url))->toBe('content-length;content-type;host;if-none-match');
});

it('also signs the checksum header when given', function () {
    config(['filesystems.disks.s3.key' => 'k', 'filesystems.disks.s3.secret' => 's']);
    $req = app(StorageGateway::class)->presignPut(ObjectKey::fromString('users/1/a.txt'), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900, hash('sha256', 'x'));

    expect(signedHeaders($req->url))->toContain('x-amz-checksum-sha256')->toContain('content-length');
});

it('binds the part length into presigned UploadPart signatures', function () {
    config(['filesystems.disks.s3.key' => 'k', 'filesystems.disks.s3.secret' => 's']);
    $req = app(StorageGateway::class)->presignPart(ObjectKey::fromString('users/1/a.txt'), 'upload-1', 2, 16777216, 3600);

    expect(signedHeaders($req->url))->toBe('content-length;host')
        ->and($req->method)->toBe('PUT')
        ->and($req->headers)->toBe(['Content-Length' => '16777216'])
        ->and($req->url)->toContain('partNumber=2')->toContain('uploadId=upload-1');
});

it('resolves the gateway without static keys (task role)', function () {
    config(['filesystems.disks.s3.key' => null, 'filesystems.disks.s3.secret' => null]);

    expect(app(StorageGateway::class))->toBeInstanceOf(StorageGateway::class);
});
