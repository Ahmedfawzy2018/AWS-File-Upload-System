<?php

declare(strict_types=1);

namespace App\Domain\Files\Rules;

use App\Domain\Files\Exceptions\ChecksumNotSupported;
use App\Domain\Files\Exceptions\FileTooLarge;
use App\Domain\Files\Exceptions\UnsupportedMediaType;
use App\Domain\Files\ValueObjects\FileSize;
use App\Domain\Files\ValueObjects\MimeType;

final readonly class UploadRules
{
    /**
     * @param  list<string>  $allowedMimeTypes
     */
    public function __construct(
        private array $allowedMimeTypes,
        private int $maxSizeBytes,
        private int $multipartThreshold,
    ) {}

    public function assertAcceptable(MimeType $type, FileSize $size, ?string $checksumSha256): void
    {
        if (! in_array($type->value(), $this->allowedMimeTypes, true)) {
            throw new UnsupportedMediaType($type->value(), $this->allowedMimeTypes);
        }

        if ($size->bytes() > $this->maxSizeBytes) {
            throw new FileTooLarge($this->maxSizeBytes);
        }

        if ($checksumSha256 !== null && $size->requiresMultipart($this->multipartThreshold)) {
            throw new ChecksumNotSupported;
        }
    }
}
