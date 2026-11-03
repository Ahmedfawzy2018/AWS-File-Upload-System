<?php

declare(strict_types=1);

use App\Domain\Files\Exceptions\InvalidParts;
use App\Domain\Files\ValueObjects\FileSize;
use App\Domain\Files\ValueObjects\MimeType;
use App\Domain\Files\ValueObjects\ObjectKey;
use Tests\Support\FakeStorageGateway;

it('head returns null for missing and metadata for stored objects', function () {
    $fake = new FakeStorageGateway;
    $k = ObjectKey::fromString('users/1/2026/01/a.txt');
    expect($fake->head($k))->toBeNull();

    $fake->putObject('users/1/2026/01/a.txt', 5, 'text/plain');

    expect($fake->head($k))->sizeBytes->toBe(5)->contentType->toBe('text/plain')->versionId->toBe('v1');
    expect($fake->objects())->toHaveKey('users/1/2026/01/a.txt');
});

it('presignPut returns a fake url and the signed headers', function () {
    $req = (new FakeStorageGateway)->presignPut(ObjectKey::fromString('users/1/a.txt'), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900, hash('sha256', 'hello'));

    expect($req->method)->toBe('PUT')
        ->and($req->url)->toStartWith('https://fake-s3.test/users/1/a.txt?sig=')
        ->and($req->headers)->toHaveKeys(['Content-Type', 'Content-Length', 'If-None-Match', 'x-amz-checksum-sha256'])
        ->and($req->expiresAt->isFuture())->toBeTrue();
});

it('failCompleteWith makes completeMultipart throw the given error', function () {
    $fake = new FakeStorageGateway;
    $fake->failCompleteWith(new InvalidParts('bad'));

    $fake->completeMultipart(ObjectKey::fromString('users/1/a.txt'), 'up', [1 => 'e']);
})->throws(InvalidParts::class);

it('completeMultipart creates the object; abort and deleteVersion are recorded', function () {
    $fake = new FakeStorageGateway;
    $k = ObjectKey::fromString('users/1/a.txt');
    $id = $fake->createMultipart($k, MimeType::fromString('text/plain'));
    $fake->completeMultipart($k, $id, [1 => 'e']);
    expect($fake->head($k))->not->toBeNull();

    $fake->abortMultipart($k, $id);
    $fake->deleteVersion($k, 'v1');

    expect($fake->aborted())->toBe([$id])
        ->and($fake->deletedVersions())->toBe([['users/1/a.txt', 'v1']])
        ->and($fake->head($k))->toBeNull();
});

it('delete removes the object', function () {
    $fake = new FakeStorageGateway;
    $fake->putObject('users/1/a.txt', 1, 'text/plain');
    $fake->delete(ObjectKey::fromString('users/1/a.txt'));
    expect($fake->objects())->toBe([]);
});
