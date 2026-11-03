<?php

declare(strict_types=1);

namespace App\Domain\Files\Exceptions;

final class ChecksumNotSupported extends DomainError
{
    public function __construct()
    {
        parent::__construct('Checksums are only supported for single-part uploads.');
    }

    public function errorCode(): string
    {
        return 'CHECKSUM_NOT_SUPPORTED';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
