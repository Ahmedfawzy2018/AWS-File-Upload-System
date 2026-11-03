<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use App\Domain\Files\Contracts\FileRepository;
use App\Domain\Files\Enums\FileStatus;
use App\Jobs\DeleteObjectJob;
use App\Jobs\VerifyUploadJob;
use Illuminate\Support\Facades\Log;

class S3EventConsumer
{
    public function __construct(private readonly FileRepository $files) {}

    public function handleKey(string $objectKey): void
    {
        $file = $this->files->findByObjectKey($objectKey);
        if ($file === null) {
            Log::warning('s3_event.unknown_key', ['object_key' => $objectKey]);

            return;
        }

        switch ($file->status) {
            case FileStatus::Pending:
                VerifyUploadJob::dispatch($file->id);
                break;
            case FileStatus::Expired:
            case FileStatus::Rejected:
            case FileStatus::Deleted:
                // An object for a file that can never become ready (late PUT after abort/expiry/delete,
                // or a re-PUT once the earlier version is gone): remove the new current version for good.
                // Quota was already released. For a deleted ready file only the new version goes; the
                // noncurrent recovery copy of the original stays until the lifecycle rule purges it.
                Log::info('s3_event.orphan_object_deleted', ['file_id' => $file->id, 'status' => $file->status->value]);
                DeleteObjectJob::dispatch($objectKey, true);
                break;
            default:
                Log::warning('s3_event.unexpected_object_created', ['file_id' => $file->id, 'status' => $file->status->value]);
        }
    }
}
