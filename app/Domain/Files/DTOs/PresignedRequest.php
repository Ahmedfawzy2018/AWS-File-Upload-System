<?php

declare(strict_types=1);

namespace App\Domain\Files\DTOs;

use Carbon\CarbonImmutable;

final readonly class PresignedRequest
{
    /**
     * @param  array<string, string>  $headers  headers the client must send verbatim
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public CarbonImmutable $expiresAt,
    ) {}
}
