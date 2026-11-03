<?php

declare(strict_types=1);

namespace App\Domain\Files\Actions;

use App\Domain\Files\Contracts\FileRepository;
use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Enums\UploadType;
use App\Domain\Files\Services\QuotaLedger;
use App\Models\File;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExpireStaleUploads
{
    public function __construct(
        private readonly FileRepository $files,
        private readonly StorageGateway $storage,
        private readonly QuotaLedger $ledger,
    ) {}

    /**
     * @return int number of files expired
     */
    public function handle(CarbonImmutable $now, int $batchSize = 200): int
    {
        $cutoff = $now->subSeconds((int) config('uploads.cleanup_grace_seconds'));
        $expired = 0;

        foreach ($this->files->stalePending($cutoff, $batchSize) as $stale) {
            if ($this->expireLocked($stale->id)) {
                $expired++;
            }
        }

        return $expired;
    }

    private function expireLocked(string $fileId): bool
    {
        $complete = Cache::lock("files:{$fileId}:complete", 60);

        if (! $complete->get()) {
            return false;
        }

        try {
            $verify = Cache::lock("files:{$fileId}:verify", 60);

            if (! $verify->get()) {
                return false;
            }

            try {
                return $this->expire($fileId);
            } finally {
                $verify->release();
            }
        } finally {
            $complete->release();
        }
    }

    private function expire(string $fileId): bool
    {
        $file = File::find($fileId);

        if ($file === null || $file->status !== FileStatus::Pending) {
            return false;
        }

        try {
            $this->cleanStorage($file);
        } catch (Throwable $e) {
            Log::warning('upload.cleanup_failed', ['file_id' => $fileId, 'exception' => $e::class]);

            return false;
        }

        return DB::transaction(function () use ($fileId): bool {
            $file = File::query()->lockForUpdate()->find($fileId);

            if ($file === null || $file->status !== FileStatus::Pending) {
                return false;
            }

            $file->transitionTo(FileStatus::Expired);
            $file->failure_reason = 'ttl_expired';
            $file->save();

            $this->ledger->release($file->user_id, $file->size_bytes);

            Log::info('upload.expired', ['file_id' => $fileId]);

            return true;
        });
    }

    private function cleanStorage(File $file): void
    {
        if ($file->upload_type === UploadType::Multipart && $file->multipart_completed_at === null) {
            $this->storage->abortMultipart($file->objectKey(), (string) $file->multipart_upload_id);
        }

        $meta = $this->storage->head($file->objectKey());

        if ($meta === null) {
            return;
        }

        if ($meta->versionId !== null) {
            $this->storage->deleteVersion($file->objectKey(), $meta->versionId);
        } else {
            $this->storage->delete($file->objectKey());
        }
    }
}
