<?php

declare(strict_types=1);

namespace App\Domain\Files\ValueObjects;

final readonly class FileSize
{
    private function __construct(private int $bytes) {}

    public static function fromBytes(int $bytes): self
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('File size must be at least 1 byte.');
        }

        return new self($bytes);
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function requiresMultipart(int $threshold): bool
    {
        return $this->bytes > $threshold;
    }

    public function partCount(int $partSize): int
    {
        return intdiv($this->bytes + $partSize - 1, $partSize);
    }
}
