<?php

declare(strict_types=1);

namespace App\Domain\Files\Exceptions;

use App\Domain\Files\Enums\FileStatus;

final class InvalidState extends DomainError
{
    public function __construct(FileStatus $current, string $action)
    {
        parent::__construct("Cannot {$action} a file in state {$current->value}.");
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
