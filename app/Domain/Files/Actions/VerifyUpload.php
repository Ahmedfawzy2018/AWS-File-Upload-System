<?php

declare(strict_types=1);

namespace App\Domain\Files\Actions;

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\DTOs\ObjectMetadata;
use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Events\FileReady;
use App\Domain\Files\Events\FileRejected;
use App\Domain\Files\Events\FileUploaded;
use App\Domain\Files\Services\QuotaLedger;
use App\Domain\Files\ValueObjects\MimeType;
use App\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class VerifyUpload
{
    public function __construct(
        private readonly StorageGateway $storage,
        private readonly QuotaLedger $ledger,
    ) {}

    public function handle(string $fileId): void
    {
        $file = File::find($fileId);

        if ($file === null || $file->status !== FileStatus::Pending) {
            return;
        }

        $meta = $this->storage->head($file->objectKey());

        if ($meta === null) {
            Log::info('upload.verify.object_missing', ['file_id' => $fileId]);

            return;
        }

        DB::transaction(function () use ($fileId, $meta): void {
            $file = File::query()->lockForUpdate()->find($fileId);

            if ($file === null || $file->status !== FileStatus::Pending) {
                return;
            }

            $reason = $this->mismatch($file, $meta);

            if ($reason !== null) {
                $this->reject($file, $meta, $reason);

                return;
            }

            $this->accept($file, $meta);
        });
    }

    private function mismatch(File $file, ObjectMetadata $meta): ?string
    {
        if ($meta->sizeBytes !== $file->size_bytes) {
            return 'size_mismatch';
        }

        if (MimeType::fromString($meta->contentType)->value() !== MimeType::fromString($file->mime_type)->value()) {
            return 'content_type_mismatch';
        }

        return null;
    }

    private function reject(File $file, ObjectMetadata $meta, string $reason): void
    {
        if ($meta->versionId !== null) {
            $this->storage->deleteVersion($file->objectKey(), $meta->versionId);
        } else {
            $this->storage->delete($file->objectKey());
        }

        $file->transitionTo(FileStatus::Rejected);
        $file->failure_reason = $reason;
        $file->save();

        $this->ledger->release($file->user_id, $file->size_bytes);

        Log::warning('upload.rejected', ['file_id' => $file->id, 'reason' => $reason]);
        event(new FileRejected($file->id, $reason));
    }

    private function accept(File $file, ObjectMetadata $meta): void
    {
        $file->etag = $meta->etag;
        $file->version_id = $meta->versionId;

        $file->transitionTo(FileStatus::Uploaded);
        event(new FileUploaded($file->id));

        $file->transitionTo(FileStatus::Ready);
        $file->save();

        $this->ledger->commit($file->user_id, $file->size_bytes);

        Log::info('upload.ready', ['file_id' => $file->id, 'size_bytes' => $file->size_bytes]);
        event(new FileReady($file->id));
    }
}
