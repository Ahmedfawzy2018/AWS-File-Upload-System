<?php

declare(strict_types=1);

namespace App\Domain\Files\Exceptions;

abstract class DomainError extends \RuntimeException
{
    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [];
    }
}
