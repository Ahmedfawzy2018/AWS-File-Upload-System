<?php

declare(strict_types=1);

use App\Infrastructure\Queue\S3EventParser;

it('extracts decoded keys from ObjectCreated records', fn () => expect(S3EventParser::objectKeys(fixture('s3-put.json')))->toBe(['users/1/2026/11/01JB.png']));

it('decodes + and %xx', function () {
    $body = json_encode(['Records' => [[
        'eventName' => 'ObjectCreated:CompleteMultipartUpload',
        's3' => ['object' => ['key' => 'users/1/a+b%2Bc.txt']],
    ]]]);

    expect(S3EventParser::objectKeys((string) $body))->toBe(['users/1/a b+c.txt']);
});

it('ignores non ObjectCreated records', function () {
    $body = json_encode(['Records' => [[
        'eventName' => 'ObjectRemoved:Delete',
        's3' => ['object' => ['key' => 'users/1/x.txt']],
    ]]]);

    expect(S3EventParser::objectKeys((string) $body))->toBe([]);
});

it('ignores test events and garbage', function () {
    expect(S3EventParser::objectKeys('{"Event":"s3:TestEvent"}'))->toBe([])
        ->and(S3EventParser::objectKeys('not json'))->toBe([])
        ->and(S3EventParser::objectKeys('{"Records":[{"eventName":"ObjectCreated:Put"}]}'))->toBe([]);
});
