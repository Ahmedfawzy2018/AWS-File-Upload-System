<?php

declare(strict_types=1);

use App\Domain\Files\Contracts\FileRepository;
use App\Domain\Files\Enums\FileStatus;
use App\Infrastructure\Queue\S3EventConsumer;
use App\Jobs\DeleteObjectJob;
use App\Jobs\VerifyUploadJob;
use App\Models\File;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/** Test double recording SQS calls; batches are served in order, then empty results. */
function fakeSqs(array $batches): SqsClient
{
    return new class($batches) extends SqsClient
    {
        /** @var list<array{string, array<string, mixed>}> */
        public array $calls = [];

        /** @param  list<list<array<string, string>>>  $batches */
        public function __construct(private array $batches) {}

        public function __call($name, array $args)
        {
            $this->calls[] = [$name, $args[0] ?? []];

            return $name === 'receiveMessage'
                ? new Result(['Messages' => array_shift($this->batches) ?? []])
                : new Result([]);
        }
    };
}

function sqsMessage(string $id, string $body): array
{
    return ['MessageId' => $id, 'ReceiptHandle' => "rh-$id", 'Body' => $body];
}

function eventBody(string $key): string
{
    return (string) json_encode(['Records' => [['eventName' => 'ObjectCreated:Put', 's3' => ['object' => ['key' => $key]]]]]);
}

it('dispatches verification for pending files', function () {
    Queue::fake();
    $file = pendingFile();

    app(S3EventConsumer::class)->handleKey($file->object_key);

    Queue::assertPushed(VerifyUploadJob::class, fn ($j) => $j->fileId === $file->id);
});

it('permanently deletes objects that arrive after expiry without touching quota', function () {
    Queue::fake();
    $file = pendingFile();
    $file->status = FileStatus::Expired;
    $file->save();
    $before = usageOf($file->user_id);

    app(S3EventConsumer::class)->handleKey($file->object_key);

    Queue::assertPushed(DeleteObjectJob::class, fn ($j) => $j->objectKey === $file->object_key && $j->permanent === true);
    Queue::assertNotPushed(VerifyUploadJob::class);
    expect(usageOf($file->user_id))->toEqual($before);
});

it('permanently deletes objects that arrive for soft-deleted or rejected files', function (FileStatus $status, bool $trashed) {
    Queue::fake();
    $file = File::factory()->create(['status' => $status]);
    if ($trashed) {
        $file->delete();
    }

    app(S3EventConsumer::class)->handleKey($file->object_key);

    Queue::assertPushed(DeleteObjectJob::class, fn ($j) => $j->objectKey === $file->object_key && $j->permanent === true);
    Queue::assertNotPushed(VerifyUploadJob::class);
})->with([
    'soft-deleted expired' => [FileStatus::Expired, true],
    'soft-deleted deleted' => [FileStatus::Deleted, true],
    'rejected' => [FileStatus::Rejected, false],
]);

it('exploit path A: reserve, abort, delete, then PUT with the still-valid URL leaves no object and no quota', function () {
    $user = actingAsUser();
    $id = $this->postJson('/api/v1/uploads', payload(5, 'text/plain'))->assertCreated()->json('id');
    $this->postJson("/api/v1/uploads/{$id}/abort")->assertOk();
    $this->deleteJson("/api/v1/files/{$id}")->assertNoContent();
    $key = File::withTrashed()->findOrFail($id)->object_key;

    fakeS3()->putObject($key, 5, 'text/plain', 'v-late');
    app(S3EventConsumer::class)->handleKey($key);

    expect(fakeS3()->objects())->not->toHaveKey($key)
        ->and(fakeS3()->deletedVersions())->toContain([$key, 'v-late'])
        ->and(usageOf($user->id))->usedBytes->toBe(0)->reservedBytes->toBe(0);
});

it('exploit path B: delete a ready file, then re-PUT with the same URL removes only the new version', function () {
    $user = actingAsUser();
    $id = $this->postJson('/api/v1/uploads', payload(5, 'text/plain'))->assertCreated()->json('id');
    $key = File::findOrFail($id)->object_key;
    fakeS3()->putObject($key, 5, 'text/plain', 'v1');
    $this->postJson("/api/v1/uploads/{$id}/complete")->assertStatus(202);
    expect(File::findOrFail($id)->status)->toBe(FileStatus::Ready);
    $this->deleteJson("/api/v1/files/{$id}")->assertNoContent();

    fakeS3()->putObject($key, 5, 'text/plain', 'v2');
    app(S3EventConsumer::class)->handleKey($key);

    expect(fakeS3()->objects())->not->toHaveKey($key)
        ->and(fakeS3()->deletedVersions())->toBe([[$key, 'v2']])
        ->and(usageOf($user->id))->usedBytes->toBe(0)->reservedBytes->toBe(0);
});

it('logs and ignores unexpected events for ready files', function () {
    Queue::fake();
    Log::spy();
    $file = File::factory()->ready()->create();

    app(S3EventConsumer::class)->handleKey($file->object_key);

    Queue::assertNothingPushed();
    Log::shouldHaveReceived('warning')->with('s3_event.unexpected_object_created', ['file_id' => $file->id, 'status' => 'ready']);
});

it('ignores unknown keys', function () {
    Queue::fake();
    Log::spy();

    app(S3EventConsumer::class)->handleKey('users/nope/x.txt');

    Queue::assertNothingPushed();
    Log::shouldHaveReceived('warning')->with('s3_event.unknown_key', ['object_key' => 'users/nope/x.txt']);
});

it('DeleteObjectJob permanent removes the version, otherwise a plain delete', function () {
    $s3 = fakeS3();
    $s3->putObject('users/1/a.txt', 1, 'text/plain', 'v9');
    (new DeleteObjectJob('users/1/a.txt', true))->handle($s3);
    expect($s3->deletedVersions())->toBe([['users/1/a.txt', 'v9']]);

    $s3->putObject('users/1/b.txt', 1, 'text/plain', null);
    (new DeleteObjectJob('users/1/b.txt', true))->handle($s3);
    expect($s3->objects())->toBeEmpty()->and($s3->deletedVersions())->toHaveCount(1);

    (new DeleteObjectJob('users/1/missing.txt', true))->handle($s3);
    expect($s3->deletedVersions())->toHaveCount(1);

    $s3->putObject('users/1/c.txt', 1, 'text/plain', 'v2');
    (new DeleteObjectJob('users/1/c.txt', false))->handle($s3);
    expect($s3->objects())->toBeEmpty()->and($s3->deletedVersions())->toHaveCount(1);
});

it('DeleteObjectJob is queued on maintenance', function () {
    $job = new DeleteObjectJob('k', true);
    expect($job->queue)->toBe('maintenance')->and($job->tries)->toBe(3)->and($job->backoff)->toBe([10, 60]);
});

it('command deletes handled messages and leaves failed ones', function () {
    Queue::fake();
    config(['uploads.s3_events_queue_url' => 'http://sqs/q']);
    $ok = pendingFile();
    $sqs = fakeSqs([[
        sqsMessage('m1', eventBody($ok->object_key)),
        sqsMessage('m2', eventBody('users/unknown/x.txt')),
        sqsMessage('m3', 'garbage'),
        sqsMessage('m4', eventBody('boom')),
    ]]);
    app()->instance(SqsClient::class, $sqs);
    $this->app->bind(S3EventConsumer::class, fn () => new class(app(FileRepository::class)) extends S3EventConsumer
    {
        public function handleKey(string $objectKey): void
        {
            if ($objectKey === 'boom') {
                throw new RuntimeException('x');
            }
            parent::handleKey($objectKey);
        }
    });
    Log::spy();

    $this->artisan('uploads:consume-s3-events', ['--once' => true])->assertExitCode(0);

    $deleted = collect($sqs->calls)->where(0, 'deleteMessage')->map(fn ($c) => $c[1]['ReceiptHandle'])->values()->all();
    expect($deleted)->toBe(['rh-m1', 'rh-m2', 'rh-m3']);
    $recv = collect($sqs->calls)->firstWhere(0, 'receiveMessage')[1];
    expect($recv)->toMatchArray(['QueueUrl' => 'http://sqs/q', 'WaitTimeSeconds' => 20, 'MaxNumberOfMessages' => 10]);
    Queue::assertPushed(VerifyUploadJob::class, 1);
    Log::shouldHaveReceived('error')->with('s3_event.handle_failed', ['message_id' => 'm4', 'exception' => RuntimeException::class]);
});

it('command exits 0 once on an empty queue', function () {
    config(['uploads.s3_events_queue_url' => 'http://sqs/q']);
    app()->instance(SqsClient::class, fakeSqs([]));

    $this->artisan('uploads:consume-s3-events', ['--once' => true])->assertExitCode(0);
});
