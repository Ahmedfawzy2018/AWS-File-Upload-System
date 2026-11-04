<?php

declare(strict_types=1);

namespace App\Domain\Files\Actions;

use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Events\FileDeleted;
use App\Domain\Files\Exceptions\InvalidState;
use App\Domain\Files\Services\QuotaLedger;
use App\Jobs\DeleteObjectJob;
use App\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class DeleteFile
{
    public function __construct(private readonly QuotaLedger $ledger) {}

    public function handle(File $file): void
    {
        DB::transaction(function () use ($file): void {
            // Re-read under a row lock so concurrent deletes release quota once.
            $file->setRawAttributes(File::query()->lockForUpdate()->findOrFail($file->id)->getAttributes(), true);

            $wasReady = $file->status === FileStatus::Ready;

            if (! $wasReady && ! in_array($file->status, [FileStatus::Rejected, FileStatus::Expired], true)) {
                throw new InvalidState($file->status, 'delete');
            }

            $file->transitionTo(FileStatus::Deleted);
            $file->save();
            $file->delete();

            if (! $wasReady) {
                return;
            }

            $this->ledger->releaseUsed($file->user_id, $file->size_bytes);
            DeleteObjectJob::dispatch($file->object_key, false)->afterCommit();
            Event::dispatch(new FileDeleted($file->id));
        });
    }
}
