<?php

declare(strict_types=1);

namespace App\Domain\Files\Exceptions;

final class FileTooLarge extends DomainError
{
    public function __construct(private readonly int $maxSizeBytes)
    {
        parent::__construct("File exceeds the maximum size of {$maxSizeBytes} bytes.");
    }

    public function errorCode(): string
    {
        return 'FILE_TOO_LARGE';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['max_size_bytes' => $this->maxSizeBytes];
    }
}
