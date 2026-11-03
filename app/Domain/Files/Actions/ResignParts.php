<?php

declare(strict_types=1);

namespace App\Domain\Files\Actions;

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Enums\UploadType;
use App\Domain\Files\Exceptions\InvalidParts;
use App\Domain\Files\Exceptions\InvalidState;
use App\Domain\Files\ValueObjects\FileSize;
use App\Models\File;

final class ResignParts
{
    public function __construct(private readonly StorageGateway $storage) {}

    /**
     * @param  list<int>  $partNumbers
     * @return list<array{part_number: int, url: string, headers: array<string, string>}>
     */
    public function handle(File $file, array $partNumbers): array
    {
        if ($file->status !== FileStatus::Pending || $file->upload_type !== UploadType::Multipart || $file->multipart_completed_at !== null) {
            throw new InvalidState($file->status, 're-sign parts of');
        }

        $size = FileSize::fromBytes($file->size_bytes);
        $partSize = (int) config('uploads.part_size_bytes');
        $count = $size->partCount($partSize);
        $invalid = array_values(array_filter($partNumbers, static fn (int $n): bool => $n < 1 || $n > $count));
        if ($invalid !== []) {
            throw new InvalidParts('Part numbers are out of range.', ['expected_parts' => $count, 'unexpected' => $invalid]);
        }

        $ttl = (int) config('uploads.ttl.multipart');
        $uploadId = (string) $file->multipart_upload_id;

        return array_map(function (int $n) use ($file, $uploadId, $size, $partSize, $ttl): array {
            $part = $this->storage->presignPart($file->objectKey(), $uploadId, $n, $size->partLength($partSize, $n), $ttl);

            return ['part_number' => $n, 'url' => $part->url, 'headers' => $part->headers];
        }, $partNumbers);
    }
}
