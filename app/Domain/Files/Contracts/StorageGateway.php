<?php

declare(strict_types=1);

namespace App\Domain\Files\Contracts;

use App\Domain\Files\DTOs\ObjectMetadata;
use App\Domain\Files\DTOs\PresignedRequest;
use App\Domain\Files\Exceptions\InvalidParts;
use App\Domain\Files\Exceptions\StorageError;
use App\Domain\Files\ValueObjects\FileSize;
use App\Domain\Files\ValueObjects\MimeType;
use App\Domain\Files\ValueObjects\ObjectKey;

interface StorageGateway
{
    /** @throws StorageError */
    public function presignPut(ObjectKey $key, MimeType $type, FileSize $size, int $ttlSeconds, ?string $checksumSha256Hex = null): PresignedRequest;

    /**
     * @return string the upload id
     *
     * @throws StorageError
     */
    public function createMultipart(ObjectKey $key, MimeType $type): string;

    /** @throws StorageError */
    public function presignPart(ObjectKey $key, string $uploadId, int $partNumber, int $ttlSeconds): PresignedRequest;

    /**
     * @param  array<int, string>  $parts  partNumber => etag
     *
     * @throws InvalidParts
     * @throws StorageError
     */
    public function completeMultipart(ObjectKey $key, string $uploadId, array $parts): void;

    /** @throws StorageError */
    public function abortMultipart(ObjectKey $key, string $uploadId): void;

    /**
     * @return ObjectMetadata|null null when the object does not exist
     *
     * @throws StorageError
     */
    public function head(ObjectKey $key): ?ObjectMetadata;

    /** @throws StorageError */
    public function presignGet(ObjectKey $key, string $downloadName, int $ttlSeconds): PresignedRequest;

    /** @throws StorageError */
    public function delete(ObjectKey $key): void;

    /** @throws StorageError */
    public function deleteVersion(ObjectKey $key, string $versionId): void;
}
