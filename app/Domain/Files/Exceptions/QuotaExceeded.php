<?php

declare(strict_types=1);

namespace App\Domain\Files\Exceptions;

final class QuotaExceeded extends DomainError
{
    public function __construct(private readonly int $remainingBytes)
    {
        parent::__construct('Storage quota exceeded.');
    }

    public function errorCode(): string
    {
        return 'QUOTA_EXCEEDED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['remaining_bytes' => $this->remainingBytes];
    }
}
