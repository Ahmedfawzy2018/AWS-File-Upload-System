<?php

declare(strict_types=1);

namespace App\Domain\Files\DTOs;

final readonly class ObjectMetadata
{
    public function __construct(
        public int $sizeBytes,
        public string $contentType,
        public string $etag,
        public ?string $versionId,
    ) {}
}
