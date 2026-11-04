<?php

declare(strict_types=1);

namespace App\Domain\Files\Exceptions;

final class InvalidParts extends DomainError
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'INVALID_PARTS';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return $this->details;
    }
}
