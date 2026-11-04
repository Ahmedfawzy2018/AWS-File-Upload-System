<?php

declare(strict_types=1);

namespace App\Http\Exceptions;

use App\Domain\Files\Exceptions\DomainError;

final class IdempotentRequestInProgress extends DomainError
{
    public function __construct()
    {
        parent::__construct('Request in progress.');
    }

    public function errorCode(): string
    {
        return 'INVALID_STATE';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
