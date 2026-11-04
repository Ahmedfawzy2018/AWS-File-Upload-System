<?php

declare(strict_types=1);

namespace App\Domain\Files\Exceptions;

final class UnsupportedMediaType extends DomainError
{
    /**
     * @param  list<string>  $allowed
     */
    public function __construct(string $mime, private readonly array $allowed)
    {
        parent::__construct("File type {$mime} is not allowed.");
    }

    public function errorCode(): string
    {
        return 'UNSUPPORTED_MEDIA_TYPE';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return ['allowed' => $this->allowed];
    }
}
