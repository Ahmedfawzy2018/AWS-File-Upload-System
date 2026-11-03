<?php

declare(strict_types=1);

namespace App\Domain\Files\Actions;

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\DTOs\PresignedRequest;
use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Exceptions\InvalidState;
use App\Models\File;

final class GetDownloadUrl
{
    public function __construct(private readonly StorageGateway $storage) {}

    public function handle(File $file): PresignedRequest
    {
        if ($file->status !== FileStatus::Ready) {
            throw new InvalidState($file->status, 'download');
        }

        return $this->storage->presignGet(
            $file->objectKey(),
            $file->original_name,
            (int) config('uploads.ttl.download'),
        );
    }
}
