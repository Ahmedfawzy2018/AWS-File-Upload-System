<?php

namespace App\Console\Commands;

use App\Infrastructure\Queue\S3EventConsumer;
use App\Infrastructure\Queue\S3EventParser;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConsumeS3Events extends Command
{
    protected $signature = 'uploads:consume-s3-events {--once : Process a single receive batch and exit}';

    protected $description = 'Long-poll the S3 events SQS queue and trigger upload verification';

    private bool $stop = false;

    public function handle(SqsClient $sqs, S3EventConsumer $consumer): int
    {
        $queueUrl = (string) config('uploads.s3_events_queue_url');

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }

        do {
            $result = $sqs->receiveMessage([
                'QueueUrl' => $queueUrl,
                'WaitTimeSeconds' => 20,
                'MaxNumberOfMessages' => 10,
            ]);

            foreach ($result['Messages'] ?? [] as $message) {
                $this->process($sqs, $consumer, $queueUrl, $message);
            }
        } while (! $this->option('once') && ! $this->stop);

        return self::SUCCESS;
    }

    /** @param  array<string, string>  $message */
    private function process(SqsClient $sqs, S3EventConsumer $consumer, string $queueUrl, array $message): void
    {
        try {
            foreach (S3EventParser::objectKeys($message['Body']) as $key) {
                $consumer->handleKey($key);
            }
        } catch (Throwable $e) {
            // Left undeleted: SQS redelivers, then moves it to the DLQ after maxReceiveCount.
            Log::error('s3_event.handle_failed', ['message_id' => $message['MessageId'] ?? null, 'exception' => $e::class]);

            return;
        }

        $sqs->deleteMessage(['QueueUrl' => $queueUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
    }
}
