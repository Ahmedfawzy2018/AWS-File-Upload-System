<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\DTOs\ObjectMetadata;
use App\Domain\Files\DTOs\PresignedRequest;
use App\Domain\Files\Exceptions\DomainError;
use App\Domain\Files\Exceptions\InvalidParts;
use App\Domain\Files\ValueObjects\FileSize;
use App\Domain\Files\ValueObjects\MimeType;
use App\Domain\Files\ValueObjects\ObjectKey;
use Carbon\CarbonImmutable;

final class FakeStorageGateway implements StorageGateway
{
    /** @var array<string, array{size: int, type: string, versionId: ?string}> */
    private array $objects = [];

    /** @var array<string, array{key: string, type: string}> */
    private array $multiparts = [];

    /** @var list<array{string, string}> */
    private array $deletedVersions = [];

    /** @var list<string> */
    private array $aborted = [];

    /** @var list<string> */
    private array $completed = [];

    /** @var array<string, array<int, string>> upload id => parts in the order sent to S3 */
    private array $completedParts = [];

    /** @var array<string, int> */
    private array $multipartSizes = [];

    private ?DomainError $completeFailure = null;

    /** @var (callable(): void)|null */
    private $afterHead = null;

    private int $uploadSeq = 0;

    public function putObject(string $key, int $size, string $type, ?string $versionId = 'v1'): void
    {
        $this->objects[$key] = ['size' => $size, 'type' => $type, 'versionId' => $versionId];
    }

    /** @return array<string, array{size: int, type: string, versionId: ?string}> */
    public function objects(): array
    {
        return $this->objects;
    }

    /** @return list<array{string, string}> [key, versionId] pairs */
    public function deletedVersions(): array
    {
        return $this->deletedVersions;
    }

    /** @return list<string> upload ids */
    public function aborted(): array
    {
        return $this->aborted;
    }

    /** @return list<string> upload ids for which completeMultipart succeeded */
    public function completedUploads(): array
    {
        return $this->completed;
    }

    /** @return array<int, string> parts (ascending, like the real adapter) sent for the upload */
    public function completedParts(string $uploadId): array
    {
        return $this->completedParts[$uploadId] ?? [];
    }

    /** One-shot: the next completeMultipart call throws $e, later calls succeed. */
    public function failCompleteWith(DomainError $e): void
    {
        $this->completeFailure = $e;
    }

    /** One-shot: $fn runs right after the next head() lookup (simulates a race after HEAD). */
    public function afterNextHead(callable $fn): void
    {
        $this->afterHead = $fn;
    }

    /** Size of the object completeMultipart will create when none was pre-seeded with putObject. */
    public function setMultipartSize(string $uploadId, int $bytes): void
    {
        $this->multipartSizes[$uploadId] = $bytes;
    }

    public function presignPut(ObjectKey $key, MimeType $type, FileSize $size, int $ttlSeconds, ?string $checksumSha256Hex = null): PresignedRequest
    {
        $headers = [
            'Content-Type' => $type->value(),
            'Content-Length' => (string) $size->bytes(),
            'If-None-Match' => '*',
        ];
        if ($checksumSha256Hex !== null) {
            $headers['x-amz-checksum-sha256'] = base64_encode((string) hex2bin($checksumSha256Hex));
        }

        return $this->request('PUT', $key, $headers, $ttlSeconds);
    }

    public function createMultipart(ObjectKey $key, MimeType $type): string
    {
        $id = 'fake-upload-'.++$this->uploadSeq;
        $this->multiparts[$id] = ['key' => $key->value(), 'type' => $type->value()];

        return $id;
    }

    public function presignPart(ObjectKey $key, string $uploadId, int $partNumber, int $ttlSeconds): PresignedRequest
    {
        return $this->request('PUT', $key, [], $ttlSeconds, "&partNumber={$partNumber}&uploadId={$uploadId}");
    }

    /**
     * Size mechanism: a pre-seeded object (putObject) keeps its size and type; otherwise the
     * size comes from setMultipartSize() (default 0) and the type from createMultipart().
     */
    public function completeMultipart(ObjectKey $key, string $uploadId, array $parts): void
    {
        if ($this->completeFailure !== null) {
            $e = $this->completeFailure;
            $this->completeFailure = null;
            throw $e;
        }
        if (! isset($this->multiparts[$uploadId])) {
            throw new InvalidParts('Multipart completion failed: NoSuchUpload', ['s3_code' => 'NoSuchUpload']);
        }
        $existing = $this->objects[$key->value()] ?? null;
        $size = $existing['size'] ?? $this->multipartSizes[$uploadId] ?? 0;
        $type = $existing['type'] ?? $this->multiparts[$uploadId]['type'];
        unset($this->multiparts[$uploadId], $this->multipartSizes[$uploadId]);
        $this->completed[] = $uploadId;
        ksort($parts); // mirror the real adapter, which sends ascending PartNumber
        $this->completedParts[$uploadId] = $parts;
        $this->putObject($key->value(), $size, $type, $existing['versionId'] ?? 'v1');
    }

    public function abortMultipart(ObjectKey $key, string $uploadId): void
    {
        unset($this->multiparts[$uploadId]);
        $this->aborted[] = $uploadId;
    }

    public function head(ObjectKey $key): ?ObjectMetadata
    {
        $o = $this->objects[$key->value()] ?? null;
        if ($this->afterHead !== null) {
            $fn = $this->afterHead;
            $this->afterHead = null;
            $fn();
        }

        return $o === null ? null : new ObjectMetadata($o['size'], $o['type'], '"'.md5($key->value()).'"', $o['versionId']);
    }

    public function presignGet(ObjectKey $key, string $downloadName, int $ttlSeconds): PresignedRequest
    {
        return $this->request('GET', $key, [], $ttlSeconds, '&name='.rawurlencode($downloadName));
    }

    public function delete(ObjectKey $key): void
    {
        unset($this->objects[$key->value()]);
    }

    public function deleteVersion(ObjectKey $key, string $versionId): void
    {
        $this->deletedVersions[] = [$key->value(), $versionId];
        unset($this->objects[$key->value()]);
    }

    /** @param  array<string, string>  $headers */
    private function request(string $method, ObjectKey $key, array $headers, int $ttl, string $extra = ''): PresignedRequest
    {
        return new PresignedRequest(
            $method,
            'https://fake-s3.test/'.$key->value().'?sig='.bin2hex(random_bytes(8)).$extra,
            $headers,
            CarbonImmutable::now()->addSeconds($ttl),
        );
    }
}
