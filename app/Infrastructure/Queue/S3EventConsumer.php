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
                // Late upload after cleanup: remove the object for good; quota was already released.
                Log::info('s3_event.late_upload_deleted', ['file_id' => $file->id]);
                DeleteObjectJob::dispatch($objectKey, true);
                break;
            default:
                Log::warning('s3_event.unexpected_object_created', ['file_id' => $file->id, 'status' => $file->status->value]);
        }
    }
}
