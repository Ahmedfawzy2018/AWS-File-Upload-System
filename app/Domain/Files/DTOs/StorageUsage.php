<?php

declare(strict_types=1);

namespace App\Domain\Files\DTOs;

final readonly class StorageUsage
{
    public function __construct(
        public int $usedBytes,
        public int $reservedBytes,
        public int $quotaBytes,
    ) {}
}
