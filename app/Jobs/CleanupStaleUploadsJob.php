<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Files\Actions\ExpireStaleUploads;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class CleanupStaleUploadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(ExpireStaleUploads $action): void
    {
        $action->handle(CarbonImmutable::now());
    }
}
