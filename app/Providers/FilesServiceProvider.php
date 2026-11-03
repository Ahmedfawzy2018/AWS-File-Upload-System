<?php

namespace App\Providers;

use App\Domain\Files\Contracts\FileRepository;
use App\Domain\Files\Rules\UploadRules;
use App\Domain\Files\Services\QuotaLedger;
use App\Infrastructure\Persistence\EloquentFileRepository;
use Illuminate\Support\ServiceProvider;

class FilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FileRepository::class, EloquentFileRepository::class);

        $this->app->bind(UploadRules::class, fn () => new UploadRules(
            config('uploads.allowed_mime_types'),
            (int) config('uploads.max_size_bytes'),
            (int) config('uploads.multipart_threshold_bytes'),
        ));

        $this->app->bind(QuotaLedger::class, fn () => new QuotaLedger((int) config('uploads.default_quota_bytes')));
    }
}
