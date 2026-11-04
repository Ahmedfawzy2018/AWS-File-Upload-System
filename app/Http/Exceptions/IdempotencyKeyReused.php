<?php

declare(strict_types=1);

namespace App\Http\Exceptions;

use App\Domain\Files\Exceptions\DomainError;

final class IdempotencyKeyReused extends DomainError
{
    public function __construct()
    {
        parent::__construct('Idempotency-Key was already used with a different request body.');
    }

    public function errorCode(): string
    {
        return 'IDEMPOTENCY_KEY_REUSED';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
