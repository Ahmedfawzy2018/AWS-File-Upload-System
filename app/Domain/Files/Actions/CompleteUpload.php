<?php

declare(strict_types=1);

namespace App\Domain\Files\Actions;

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\DTOs\CompleteUploadData;
use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Enums\UploadType;
use App\Domain\Files\Exceptions\InvalidParts;
use App\Domain\Files\Exceptions\InvalidState;
use App\Domain\Files\ValueObjects\FileSize;
use App\Jobs\VerifyUploadJob;
use App\Models\File;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class CompleteUpload
{
    public function __construct(private readonly StorageGateway $storage) {}

    public function handle(CompleteUploadData $data): File
    {
        $file = $data->file;

        if (in_array($file->status, [FileStatus::Uploaded, FileStatus::Ready], true)) {
            return $file;
        }
        if ($file->status !== FileStatus::Pending) {
            throw new InvalidState($file->status, 'complete');
        }

        if ($file->upload_type === UploadType::Multipart && $file->multipart_completed_at === null) {
            $this->completeMultipart($file, $data->parts);
        }

        VerifyUploadJob::dispatch($file->id);

        return $file;
    }

    /**
     * @param  array<int, string>|null  $parts
     */
    private function completeMultipart(File $file, ?array $parts): void
    {
        $expected = FileSize::fromBytes($file->size_bytes)->partCount((int) config('uploads.part_size_bytes'));
        $this->assertParts($parts, $expected);

        Cache::lock("files:{$file->id}:complete", 30)->block(10, function () use ($file, $parts): void {
            $file->refresh();
            if ($file->multipart_completed_at !== null) {
                return;
            }

            $this->storage->completeMultipart($file->objectKey(), (string) $file->multipart_upload_id, $parts ?? []);
            $file->multipart_completed_at = CarbonImmutable::now();
            $file->save();
        });
    }

    /**
     * @param  array<int, string>|null  $parts
     *
     * @throws InvalidParts
     */
    private function assertParts(?array $parts, int $expected): void
    {
        if ($parts === null || $parts === []) {
            throw new InvalidParts('The parts list is required for a multipart upload.', ['expected_parts' => $expected]);
        }

        $numbers = array_keys($parts);
        $missing = array_values(array_diff(range(1, $expected), $numbers));
        $unexpected = array_values(array_diff($numbers, range(1, $expected)));

        if ($missing !== [] || $unexpected !== []) {
            throw new InvalidParts('The parts do not match the expected part numbers.', [
                'expected_parts' => $expected,
                'missing' => $missing,
                'unexpected' => $unexpected,
            ]);
        }

        foreach ($parts as $number => $etag) {
            if (trim($etag) === '') {
                throw new InvalidParts('Every part needs a non-empty etag.', ['empty_etag' => [$number]]);
            }
        }
    }
}
