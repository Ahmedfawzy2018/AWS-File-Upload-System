<?php

declare(strict_types=1);

namespace App\Domain\Files\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class ObjectKey
{
    private function __construct(private string $value) {}

    public static function generate(string $prefix, int $userId, string $ulid, MimeType $type, CarbonImmutable $now): self
    {
        return new self(sprintf('%s/%d/%s/%s.%s', $prefix, $userId, $now->format('Y/m'), $ulid, $type->extension()));
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
