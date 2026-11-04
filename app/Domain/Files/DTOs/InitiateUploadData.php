<?php

declare(strict_types=1);

namespace App\Domain\Files\DTOs;

final readonly class InitiateUploadData
{
    public function __construct(
        public int $userId,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public ?string $checksumSha256,
    ) {}
}
