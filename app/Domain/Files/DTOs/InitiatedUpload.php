<?php

declare(strict_types=1);

namespace App\Domain\Files\DTOs;

use App\Models\File;
use Carbon\CarbonImmutable;

final readonly class InitiatedUpload
{
    /**
     * @param  list<array{part_number: int, url: string, headers: array<string, string>}>  $parts  headers must be sent verbatim
     */
    public function __construct(
        public File $file,
        public ?PresignedRequest $put,
        public array $parts,
        public ?int $partSize,
        public CarbonImmutable $expiresAt,
    ) {}
}
