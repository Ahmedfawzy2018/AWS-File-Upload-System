<?php

declare(strict_types=1);

use App\Domain\Files\Exceptions\StorageError;
use App\Domain\Files\ValueObjects\ObjectKey;
use App\Infrastructure\Storage\S3StorageGateway;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;

/** @param  list<string>  $sent  command names that reached the (fake) S3 handler */
function abortGateway(string $awsCode, array &$sent = []): S3StorageGateway
{
    $client = new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => function (CommandInterface $cmd) use ($awsCode, &$sent) {
            $sent[] = $cmd->getName();

            return Create::rejectionFor(new AwsException($awsCode, $cmd, ['code' => $awsCode]));
        },
    ]);

    return new S3StorageGateway($client, 'bucket');
}

it('treats NoSuchUpload on abort as success', function () {
    $sent = [];
    $gateway = abortGateway('NoSuchUpload', $sent);

    expect(fn () => $gateway->abortMultipart(ObjectKey::fromString('users/1/a.txt'), 'gone'))->not->toThrow(Throwable::class)
        ->and($sent)->toBe(['AbortMultipartUpload']);
});

it('still throws StorageError on other abort failures', function () {
    abortGateway('AccessDenied')->abortMultipart(ObjectKey::fromString('users/1/a.txt'), 'u');
})->throws(StorageError::class);
