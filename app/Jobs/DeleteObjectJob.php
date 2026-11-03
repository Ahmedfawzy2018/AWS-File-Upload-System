<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\ValueObjects\ObjectKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class DeleteObjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $objectKey, public readonly bool $permanent)
    {
        $this->onQueue('maintenance');
    }

    public function handle(StorageGateway $storage): void
    {
        $key = ObjectKey::fromString($this->objectKey);

        if (! $this->permanent) {
            $storage->delete($key);

            return;
        }

        $head = $storage->head($key);
        if ($head === null) {
            return;
        }
        if ($head->versionId !== null) {
            $storage->deleteVersion($key, $head->versionId);

            return;
        }
        $storage->delete($key);
    }
}
