<?php

declare(strict_types=1);

namespace App\Domain\Files\ValueObjects;

final readonly class MimeType
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
        'video/mp4' => 'mp4',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    private function __construct(private string $value) {}

    public static function fromString(string $raw): self
    {
        $base = explode(';', $raw, 2)[0];

        return new self(strtolower(trim($base)));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function extension(): string
    {
        return self::EXTENSIONS[$this->value] ?? 'bin';
    }
}
