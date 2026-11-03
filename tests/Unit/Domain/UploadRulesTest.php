<?php

declare(strict_types=1);

use App\Domain\Files\Exceptions\ChecksumNotSupported;
use App\Domain\Files\Exceptions\FileTooLarge;
use App\Domain\Files\Exceptions\InvalidParts;
use App\Domain\Files\Exceptions\QuotaExceeded;
use App\Domain\Files\Exceptions\UnsupportedMediaType;
use App\Domain\Files\ValueObjects\FileSize;
use App\Domain\Files\ValueObjects\MimeType;

it('accepts exactly max size', fn () => rules()->assertAcceptable(MimeType::fromString('video/mp4'), FileSize::fromBytes(5368709120), null))->throwsNoExceptions();
it('rejects max + 1', fn () => rules()->assertAcceptable(MimeType::fromString('video/mp4'), FileSize::fromBytes(5368709121), null))->throws(FileTooLarge::class);
it('rejects disallowed mime', fn () => rules()->assertAcceptable(MimeType::fromString('application/x-msdownload'), FileSize::fromBytes(10), null))->throws(UnsupportedMediaType::class);
it('rejects checksum on multipart sizes', fn () => rules()->assertAcceptable(MimeType::fromString('video/mp4'), FileSize::fromBytes(104857601), str_repeat('a', 64)))->throws(ChecksumNotSupported::class);
it('accepts checksum on single sizes', fn () => rules()->assertAcceptable(MimeType::fromString('video/mp4'), FileSize::fromBytes(104857600), str_repeat('a', 64)))->throwsNoExceptions();

it('exposes error codes, statuses and details', function () {
    expect((new UnsupportedMediaType('image/x', ['a']))->details())->toBe(['allowed' => ['a']])
        ->and((new UnsupportedMediaType('image/x', ['a']))->getMessage())->toBe('File type image/x is not allowed.')
        ->and((new FileTooLarge(5368709120))->details())->toBe(['max_size_bytes' => 5368709120])
        ->and((new FileTooLarge(5368709120))->getMessage())->toBe('File exceeds the maximum size of 5368709120 bytes.')
        ->and((new ChecksumNotSupported)->errorCode())->toBe('CHECKSUM_NOT_SUPPORTED')
        ->and((new ChecksumNotSupported)->getMessage())->toBe('Checksums are only supported for single-part uploads.')
        ->and((new QuotaExceeded(7))->details())->toBe(['remaining_bytes' => 7])
        ->and((new QuotaExceeded(7))->httpStatus())->toBe(409)
        ->and((new QuotaExceeded(7))->errorCode())->toBe('QUOTA_EXCEEDED')
        ->and((new InvalidParts('bad', ['missing' => [1]]))->details())->toBe(['missing' => [1]])
        ->and((new InvalidParts('bad'))->errorCode())->toBe('INVALID_PARTS')
        ->and((new InvalidParts('bad'))->httpStatus())->toBe(422);
});
