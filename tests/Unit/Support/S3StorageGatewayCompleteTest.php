<?php

declare(strict_types=1);

use App\Domain\Files\ValueObjects\ObjectKey;
use App\Infrastructure\Storage\S3StorageGateway;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;

it('sends CompleteMultipartUpload parts in ascending PartNumber order', function () {
    $captured = null;
    $client = new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => function (CommandInterface $cmd) use (&$captured) {
            $captured = $cmd;

            return Create::promiseFor(new Result([]));
        },
    ]);

    (new S3StorageGateway($client, 'bucket'))->completeMultipart(
        ObjectKey::fromString('users/1/a.txt'),
        'upload-1',
        [3 => 'c', 1 => 'a', 2 => 'b'],
    );

    expect($captured)->not->toBeNull();
    expect(array_column($captured['MultipartUpload']['Parts'], 'PartNumber'))->toBe([1, 2, 3]);
    expect(array_column($captured['MultipartUpload']['Parts'], 'ETag'))->toBe(['a', 'b', 'c']);
});
