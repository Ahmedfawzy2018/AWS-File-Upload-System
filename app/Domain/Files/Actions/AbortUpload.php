<?php

declare(strict_types=1);

namespace App\Domain\Files\Actions;

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Enums\UploadType;
use App\Domain\Files\Exceptions\InvalidState;
use App\Domain\Files\Services\QuotaLedger;
use App\Jobs\DeleteObjectJob;
use App\Models\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AbortUpload
{
    public function __construct(
        private readonly StorageGateway $storage,
        private readonly QuotaLedger $ledger,
    ) {}

    public function handle(File $file): File
    {
        return Cache::lock("files:{$file->id}:complete", CompleteUpload::COMPLETE_LOCK_TTL_SECONDS)->block(10, fn (): File => $this->abort($file));
    }

    private function abort(File $file): File
    {
        return DB::transaction(function () use ($file): File {
            $file->setRawAttributes(File::query()->lockForUpdate()->findOrFail($file->id)->getAttributes(), true);

            if ($file->status !== FileStatus::Pending) {
                throw new InvalidState($file->status, 'abort');
            }

            if ($file->upload_type === UploadType::Multipart && $file->multipart_completed_at === null) {
                $this->storage->abortMultipart($file->objectKey(), (string) $file->multipart_upload_id);
            }

            $file->transitionTo(FileStatus::Expired);
            $file->failure_reason = 'aborted';
            $file->save();
            $this->ledger->release($file->user_id, $file->size_bytes);
            Log::info('upload.expired', [
                'file_id' => $file->id, 'user_id' => $file->user_id, 'size_bytes' => $file->size_bytes, 'reason' => 'aborted',
            ]);

            // The abort may have beaten verification: a finished single PUT or an assembled multipart
            // object may already be in S3. Remove it for good once the abort is committed.
            if ($file->upload_type === UploadType::Single || $file->multipart_completed_at !== null) {
                DeleteObjectJob::dispatch($file->object_key, true)->afterCommit();
            }

            return $file;
        });
    }
}
