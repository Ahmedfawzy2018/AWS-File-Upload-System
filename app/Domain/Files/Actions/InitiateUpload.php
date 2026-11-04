<?php

declare(strict_types=1);

namespace App\Domain\Files\Actions;

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\DTOs\InitiatedUpload;
use App\Domain\Files\DTOs\InitiateUploadData;
use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Enums\UploadType;
use App\Domain\Files\Rules\UploadRules;
use App\Domain\Files\Services\QuotaLedger;
use App\Domain\Files\ValueObjects\FileSize;
use App\Domain\Files\ValueObjects\MimeType;
use App\Domain\Files\ValueObjects\ObjectKey;
use App\Domain\Files\ValueObjects\OriginalName;
use App\Models\File;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class InitiateUpload
{
    public function __construct(
        private readonly UploadRules $rules,
        private readonly QuotaLedger $ledger,
        private readonly StorageGateway $storage,
    ) {}

    public function handle(InitiateUploadData $data): InitiatedUpload
    {
        $mime = MimeType::fromString($data->mimeType);
        $size = FileSize::fromBytes($data->sizeBytes);
        $this->rules->assertAcceptable($mime, $size, $data->checksumSha256);

        $multipart = $size->requiresMultipart((int) config('uploads.multipart_threshold_bytes'));
        $now = CarbonImmutable::now();
        $ttl = (int) config($multipart ? 'uploads.ttl.multipart' : 'uploads.ttl.single_put');
        $expiresAt = $now->addSeconds($ttl);
        $id = (string) Str::ulid();
        $key = ObjectKey::generate((string) config('uploads.key_prefix', 'users'), $data->userId, $id, $mime, $now);

        // The S3 round trip runs before the transaction, never while the user's usage row is locked.
        $uploadId = $multipart ? $this->storage->createMultipart($key, $mime) : null;

        try {
            $file = DB::transaction(function () use ($data, $mime, $size, $uploadId, $expiresAt, $id, $key): File {
                $this->ledger->reserve($data->userId, $size->bytes());

                return File::create([
                    'id' => $id,
                    'user_id' => $data->userId,
                    'original_name' => OriginalName::fromString($data->filename)->value(),
                    'object_key' => $key->value(),
                    'mime_type' => $mime->value(),
                    'size_bytes' => $size->bytes(),
                    'checksum_sha256' => $data->checksumSha256,
                    'status' => FileStatus::Pending,
                    'upload_type' => $uploadId !== null ? UploadType::Multipart : UploadType::Single,
                    'multipart_upload_id' => $uploadId,
                    'expires_at' => $expiresAt,
                ]);
            });
        } catch (Throwable $e) {
            if ($uploadId !== null) {
                $this->abortQuietly($key, $uploadId);
            }
            throw $e;
        }

        Log::info('upload.initiated', ['file_id' => $file->id, 'user_id' => $file->user_id, 'size_bytes' => $file->size_bytes]);

        if ($uploadId === null) {
            $put = $this->storage->presignPut($key, $mime, $size, $ttl, $data->checksumSha256);

            return new InitiatedUpload($file, $put, [], null, $put->expiresAt);
        }

        $partSize = (int) config('uploads.part_size_bytes');
        $parts = [];
        foreach (range(1, $size->partCount($partSize)) as $number) {
            $part = $this->storage->presignPart($key, $uploadId, $number, $size->partLength($partSize, $number), $ttl);
            $parts[] = ['part_number' => $number, 'url' => $part->url, 'headers' => $part->headers];
        }

        return new InitiatedUpload($file, null, $parts, $partSize, $expiresAt);
    }

    /** No row references the upload, so abort it; if that fails too, the S3 lifecycle rule cleans it up. */
    private function abortQuietly(ObjectKey $key, string $uploadId): void
    {
        try {
            $this->storage->abortMultipart($key, $uploadId);
        } catch (Throwable $e) {
            Log::warning('upload.initiate.abort_failed', ['object_key' => $key->value(), 'exception' => $e::class]);
        }
    }
}
