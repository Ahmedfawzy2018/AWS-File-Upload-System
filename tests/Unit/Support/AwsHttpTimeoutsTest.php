<?php

declare(strict_types=1);

use App\Infrastructure\Storage\S3StorageGateway;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;

function s3ClientOf(S3StorageGateway $gateway): S3Client
{
    return (fn () => $this->s3)->call($gateway);
}

it('gives the S3 and SQS clients connect and request timeouts by default', function () {
    $s3 = s3ClientOf(app(S3StorageGateway::class))->getCommand('HeadObject', ['Bucket' => 'b', 'Key' => 'k']);
    $sqs = app(SqsClient::class)->getCommand('ReceiveMessage', ['QueueUrl' => 'http://q']);

    expect($s3['@http'])->toMatchArray(['connect_timeout' => 3, 'timeout' => 30])
        ->and($sqs['@http'])->toMatchArray(['connect_timeout' => 3, 'timeout' => 30]);
});

it('reads the AWS HTTP timeouts from config', function () {
    config(['uploads.aws_http.connect_timeout' => 1.5, 'uploads.aws_http.timeout' => 12]);

    $s3 = s3ClientOf(app(S3StorageGateway::class))->getCommand('HeadObject', ['Bucket' => 'b', 'Key' => 'k']);

    expect($s3['@http'])->toMatchArray(['connect_timeout' => 1.5, 'timeout' => 12]);
});

it('keeps the request timeout above the SQS long-poll wait', function () {
    expect((float) config('uploads.aws_http.timeout'))->toBeGreaterThan(20);
});
