<?php

declare(strict_types=1);

use App\Domain\Files\ValueObjects\FileSize;

it('rejects zero bytes', fn () => FileSize::fromBytes(0))->throws(InvalidArgumentException::class);
it('exposes bytes', fn () => expect(FileSize::fromBytes(5)->bytes())->toBe(5));
it('treats exactly the threshold as single', fn () => expect(FileSize::fromBytes(104857600)->requiresMultipart(104857600))->toBeFalse());
it('treats threshold + 1 as multipart', fn () => expect(FileSize::fromBytes(104857601)->requiresMultipart(104857600))->toBeTrue());
it('computes part count', fn () => expect(FileSize::fromBytes(16777216 * 2 + 1)->partCount(16777216))->toBe(3));
it('computes exact part count', fn () => expect(FileSize::fromBytes(16777216 * 2)->partCount(16777216))->toBe(2));
it('gives every part the full part size except a shorter last part', function () {
    $size = FileSize::fromBytes(16777216 * 2 + 1);
    expect($size->partLength(16777216, 1))->toBe(16777216)
        ->and($size->partLength(16777216, 2))->toBe(16777216)
        ->and($size->partLength(16777216, 3))->toBe(1);
});
it('gives an exact multiple a full last part', fn () => expect(FileSize::fromBytes(16777216 * 2)->partLength(16777216, 2))->toBe(16777216));
it('rejects part numbers outside the part range', fn () => FileSize::fromBytes(10)->partLength(5, 3))->throws(InvalidArgumentException::class);
