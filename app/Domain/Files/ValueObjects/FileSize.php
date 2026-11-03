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

    /** Byte length of part $partNumber (1-based): $partSize for every part but the last, which holds the remainder. */
    public function partLength(int $partSize, int $partNumber): int
    {
        $count = $this->partCount($partSize);
        if ($partNumber < 1 || $partNumber > $count) {
            throw new \InvalidArgumentException("Part number {$partNumber} is outside 1..{$count}.");
        }

        return $partNumber < $count ? $partSize : $this->bytes - ($count - 1) * $partSize;
    }
}
