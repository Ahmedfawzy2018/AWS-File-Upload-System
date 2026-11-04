<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\DTOs\ObjectMetadata;
use App\Domain\Files\DTOs\PresignedRequest;
use App\Domain\Files\Exceptions\InvalidParts;
use App\Domain\Files\Exceptions\StorageError;
use App\Domain\Files\ValueObjects\FileSize;
use App\Domain\Files\ValueObjects\MimeType;
use App\Domain\Files\ValueObjects\ObjectKey;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;

final class S3StorageGateway implements StorageGateway
{
    private const INVALID_PARTS_CODES = ['InvalidPart', 'InvalidPartOrder', 'EntityTooSmall', 'NoSuchUpload'];

    public function __construct(private readonly S3Client $s3, private readonly string $bucket) {}

    public function presignPut(ObjectKey $key, MimeType $type, FileSize $size, int $ttlSeconds, ?string $checksumSha256Hex = null): PresignedRequest
    {
        $args = [
            'Bucket' => $this->bucket,
            'Key' => $key->value(),
            'ContentType' => $type->value(),
            'ContentLength' => $size->bytes(),
            'IfNoneMatch' => '*',
        ];
        $headers = [
            'Content-Type' => $type->value(),
            'Content-Length' => (string) $size->bytes(),
            'If-None-Match' => '*',
        ];
        if ($checksumSha256Hex !== null) {
            $checksum = base64_encode((string) hex2bin($checksumSha256Hex));
            $args['ChecksumSHA256'] = $checksum;
            $headers['x-amz-checksum-sha256'] = $checksum;
        }

        return $this->presignBound('PutObject', $args, $headers, $ttlSeconds);
    }

    public function createMultipart(ObjectKey $key, MimeType $type): string
    {
        return $this->call(fn () => (string) $this->s3->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key->value(),
            'ContentType' => $type->value(),
        ])['UploadId']);
    }

    public function presignPart(ObjectKey $key, string $uploadId, int $partNumber, int $partSizeBytes, int $ttlSeconds): PresignedRequest
    {
        return $this->presignBound('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $key->value(),
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
            'ContentLength' => $partSizeBytes,
        ], ['Content-Length' => (string) $partSizeBytes], $ttlSeconds);
    }

    public function completeMultipart(ObjectKey $key, string $uploadId, array $parts): void
    {
        ksort($parts); // S3 requires ascending PartNumber
        $list = [];
        foreach ($parts as $number => $etag) {
            $list[] = ['PartNumber' => $number, 'ETag' => $etag];
        }

        try {
            $this->s3->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key->value(),
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $list],
                'IfNoneMatch' => '*',
            ]);
        } catch (AwsException $e) {
            $code = (string) $e->getAwsErrorCode();
            if (in_array($code, self::INVALID_PARTS_CODES, true)) {
                throw new InvalidParts("Multipart completion failed: {$code}", ['s3_code' => $code]);
            }
            throw new StorageError('Storage operation failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function abortMultipart(ObjectKey $key, string $uploadId): void
    {
        try {
            $this->s3->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key->value(),
                'UploadId' => $uploadId,
            ]);
        } catch (AwsException $e) {
            // Already aborted or lifecycle-expired: the goal state is reached.
            if ($e->getAwsErrorCode() === 'NoSuchUpload') {
                return;
            }
            throw new StorageError('Storage operation failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function head(ObjectKey $key): ?ObjectMetadata
    {
        try {
            $r = $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $key->value()]);
        } catch (AwsException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw new StorageError('Storage operation failed: '.$e->getMessage(), 0, $e);
        }

        return new ObjectMetadata(
            (int) $r['ContentLength'],
            (string) $r['ContentType'],
            (string) $r['ETag'],
            isset($r['VersionId']) ? (string) $r['VersionId'] : null,
        );
    }

    public function presignGet(ObjectKey $key, string $downloadName, int $ttlSeconds): PresignedRequest
    {
        $fallback = str_replace(['"', '\\', '%'], '_', (string) preg_replace('/[^\x20-\x7E]/', '_', $downloadName));
        $disposition = sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, rawurlencode($downloadName));

        return $this->presign('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key->value(),
            'ResponseContentDisposition' => $disposition,
        ], [], $ttlSeconds);
    }

    public function delete(ObjectKey $key): void
    {
        $this->call(fn () => $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key->value()]));
    }

    public function deleteVersion(ObjectKey $key, string $versionId): void
    {
        $this->call(fn () => $this->s3->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key->value(),
            'VersionId' => $versionId,
        ]));
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, string>  $headers
     */
    private function presign(string $command, array $args, array $headers, int $ttlSeconds): PresignedRequest
    {
        $expiresAt = CarbonImmutable::now()->addSeconds($ttlSeconds);

        $request = $this->call(fn () => $this->s3->createPresignedRequest(
            $this->s3->getCommand($command, $args),
            $expiresAt,
        ));

        return new PresignedRequest($request->getMethod(), (string) $request->getUri(), $headers, $expiresAt);
    }

    /**
     * Presigns with whichever of Content-Type, Content-Length and If-None-Match the command carries
     * bound into the signature.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, string>  $headers
     */
    private function presignBound(string $command, array $args, array $headers, int $ttlSeconds): PresignedRequest
    {
        $expiresAt = CarbonImmutable::now()->addSeconds($ttlSeconds);

        $request = $this->call(function () use ($command, $args, $expiresAt) {
            $cmd = clone $this->s3->getCommand($command, $args);
            $list = $cmd->getHandlerList();
            $list->remove('signer');
            if (empty($cmd['ChecksumAlgorithm'])) {
                $list->remove('s3.checksum');
            }
            $signer = new HeaderBindingSignatureV4('s3', (string) $this->s3->getRegion(), []);

            $request = \Aws\serialize($cmd);
            if (! isset($args['ContentType'])) {
                // The SDK adds a default Content-Type (UploadPart); don't make clients match it.
                $request = $request->withoutHeader('Content-Type');
            }

            return $signer->presign($request, $this->s3->getCredentials()->wait(), $expiresAt);
        });

        return new PresignedRequest($request->getMethod(), (string) $request->getUri(), $headers, $expiresAt);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (AwsException $e) {
            throw new StorageError('Storage operation failed: '.$e->getMessage(), 0, $e);
        }
    }
}
