<?php

declare(strict_types=1);

namespace App\Domain\Files\Actions;

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Enums\UploadType;
use App\Domain\Files\Exceptions\InvalidState;
use App\Domain\Files\Services\QuotaLedger;
use App\Models\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AbortUpload
{
    public function __construct(
        private readonly StorageGateway $storage,
        private readonly QuotaLedger $ledger,
    ) {}

    public function handle(File $file): File
    {
        return Cache::lock("files:{$file->id}:complete", 30)->block(10, fn (): File => $this->abort($file));
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

            return $file;
        });
    }
}
