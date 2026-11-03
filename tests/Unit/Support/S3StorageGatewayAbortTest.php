<?php

declare(strict_types=1);

use App\Domain\Files\Exceptions\StorageError;
use App\Domain\Files\ValueObjects\ObjectKey;
use App\Infrastructure\Storage\S3StorageGateway;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;

function abortGateway(string $awsCode): S3StorageGateway
{
    $client = new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => fn (CommandInterface $cmd) => Create::rejectionFor(
            new AwsException($awsCode, $cmd, ['code' => $awsCode]),
        ),
    ]);

    return new S3StorageGateway($client, 'bucket');
}

it('treats NoSuchUpload on abort as success', function () {
    abortGateway('NoSuchUpload')->abortMultipart(ObjectKey::fromString('users/1/a.txt'), 'gone');

    expect(true)->toBeTrue();
});

it('still throws StorageError on other abort failures', function () {
    abortGateway('AccessDenied')->abortMultipart(ObjectKey::fromString('users/1/a.txt'), 'u');
})->throws(StorageError::class);
