<?php

declare(strict_types=1);

namespace App\Domain\Files\DTOs;

use App\Http\Requests\InitiateUploadRequest;

final readonly class InitiateUploadData
{
    public function __construct(
        public int $userId,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public ?string $checksumSha256,
    ) {}

    public static function fromRequest(InitiateUploadRequest $request): self
    {
        $checksum = $request->input('checksum_sha256');

        return new self(
            (int) $request->user()?->getAuthIdentifier(),
            (string) $request->input('filename'),
            (string) $request->input('mime_type'),
            (int) $request->input('size_bytes'),
            is_string($checksum) && $checksum !== '' ? strtolower($checksum) : null,
        );
    }
}
