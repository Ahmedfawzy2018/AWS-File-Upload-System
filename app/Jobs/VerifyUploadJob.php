<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Files\Actions\VerifyUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class VerifyUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $fileId)
    {
        $this->onQueue('uploads');
    }

    /**
     * @throws LockTimeoutException when the lock is not free within 10s (the queue retries)
     */
    public function handle(VerifyUpload $action): void
    {
        Cache::lock("files:{$this->fileId}:verify", 60)->block(10, fn () => $action->handle($this->fileId));
    }
}
