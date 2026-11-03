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
     * @return list<array{part_number: int, url: string}>
     */
    public function handle(File $file, array $partNumbers): array
    {
        if ($file->status !== FileStatus::Pending || $file->upload_type !== UploadType::Multipart || $file->multipart_completed_at !== null) {
            throw new InvalidState($file->status, 're-sign parts of');
        }

        $count = FileSize::fromBytes($file->size_bytes)->partCount((int) config('uploads.part_size_bytes'));
        $invalid = array_values(array_filter($partNumbers, static fn (int $n): bool => $n < 1 || $n > $count));
        if ($invalid !== []) {
            throw new InvalidParts('Part numbers are out of range.', ['expected_parts' => $count, 'unexpected' => $invalid]);
        }

        $ttl = (int) config('uploads.ttl.multipart');
        $uploadId = (string) $file->multipart_upload_id;

        return array_map(fn (int $n): array => [
            'part_number' => $n,
            'url' => $this->storage->presignPart($file->objectKey(), $uploadId, $n, $ttl)->url,
        ], $partNumbers);
    }
}
