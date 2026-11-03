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
use Illuminate\Support\Str;

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

        $file = DB::transaction(function () use ($data, $mime, $size, $multipart, $expiresAt, $id, $key): File {
            $this->ledger->reserve($data->userId, $size->bytes());

            $file = File::create([
                'id' => $id,
                'user_id' => $data->userId,
                'original_name' => OriginalName::fromString($data->filename)->value(),
                'object_key' => $key->value(),
                'mime_type' => $mime->value(),
                'size_bytes' => $size->bytes(),
                'checksum_sha256' => $data->checksumSha256,
                'status' => FileStatus::Pending,
                'upload_type' => $multipart ? UploadType::Multipart : UploadType::Single,
                'multipart_upload_id' => $multipart ? $this->storage->createMultipart($key, $mime) : null,
                'expires_at' => $expiresAt,
            ]);

            return $file;
        });

        if (! $multipart) {
            $put = $this->storage->presignPut($key, $mime, $size, $ttl, $data->checksumSha256);

            return new InitiatedUpload($file, $put, [], null, $put->expiresAt);
        }

        $partSize = (int) config('uploads.part_size_bytes');
        $uploadId = (string) $file->multipart_upload_id;
        $parts = [];
        foreach (range(1, $size->partCount($partSize)) as $number) {
            $part = $this->storage->presignPart($key, $uploadId, $number, $size->partLength($partSize, $number), $ttl);
            $parts[] = ['part_number' => $number, 'url' => $part->url, 'headers' => $part->headers];
        }

        return new InitiatedUpload($file, null, $parts, $partSize, $expiresAt);
    }
}
