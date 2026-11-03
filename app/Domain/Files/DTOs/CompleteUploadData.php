<?php

declare(strict_types=1);

namespace App\Domain\Files\DTOs;

use App\Models\File;

final readonly class CompleteUploadData
{
    /**
     * @param  array<int, string>|null  $parts  partNumber => etag
     */
    public function __construct(
        public File $file,
        public ?array $parts,
    ) {}
}
