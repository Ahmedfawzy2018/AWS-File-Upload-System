<?php

declare(strict_types=1);

namespace App\Domain\Files\ValueObjects;

final readonly class OriginalName
{
    private const MAX_BYTES = 255;

    private const FALLBACK = 'file';

    private function __construct(private string $value) {}

    public static function fromString(string $raw): self
    {
        $clean = trim((string) preg_replace('/[\/\\\\\x00-\x1F\x7F]/', '', $raw));

        if (strlen($clean) > self::MAX_BYTES) {
            $clean = trim(mb_strcut($clean, 0, self::MAX_BYTES, 'UTF-8'));
        }

        return new self($clean === '' ? self::FALLBACK : $clean);
    }

    public function value(): string
    {
        return $this->value;
    }
}
