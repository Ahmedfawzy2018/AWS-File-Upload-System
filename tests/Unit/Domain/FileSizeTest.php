<?php

declare(strict_types=1);

use App\Domain\Files\ValueObjects\FileSize;

it('rejects zero bytes', fn () => FileSize::fromBytes(0))->throws(InvalidArgumentException::class);
it('exposes bytes', fn () => expect(FileSize::fromBytes(5)->bytes())->toBe(5));
it('treats exactly the threshold as single', fn () => expect(FileSize::fromBytes(104857600)->requiresMultipart(104857600))->toBeFalse());
it('treats threshold + 1 as multipart', fn () => expect(FileSize::fromBytes(104857601)->requiresMultipart(104857600))->toBeTrue());
it('computes part count', fn () => expect(FileSize::fromBytes(16777216 * 2 + 1)->partCount(16777216))->toBe(3));
it('computes exact part count', fn () => expect(FileSize::fromBytes(16777216 * 2)->partCount(16777216))->toBe(2));
