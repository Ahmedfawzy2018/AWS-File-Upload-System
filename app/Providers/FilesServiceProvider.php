<?php

namespace App\Providers;

use App\Domain\Files\Contracts\FileRepository;
use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\Rules\UploadRules;
use App\Domain\Files\Services\QuotaLedger;
use App\Infrastructure\Persistence\EloquentFileRepository;
use App\Infrastructure\Storage\S3StorageGateway;
use Aws\S3\S3Client;
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

        $this->app->bind(S3StorageGateway::class, function () {
            $disk = config('filesystems.disks.s3');

            $config = [
                'version' => 'latest',
                'region' => $disk['region'],
                'endpoint' => $disk['endpoint'] ?: null,
                'use_path_style_endpoint' => (bool) $disk['use_path_style_endpoint'],
            ];
            // Without static keys (production task role) the SDK default provider chain applies.
            if (! empty($disk['key']) && ! empty($disk['secret'])) {
                $config['credentials'] = ['key' => $disk['key'], 'secret' => $disk['secret']];
            }

            return new S3StorageGateway(new S3Client($config), (string) config('uploads.bucket'));
        });
        $this->app->bind(StorageGateway::class, S3StorageGateway::class);
    }
}
