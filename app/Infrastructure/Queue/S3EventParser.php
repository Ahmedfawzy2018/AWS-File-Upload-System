<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

final class S3EventParser
{
    /**
     * Object keys of the ObjectCreated records in an S3 notification body.
     * Test events, other event types and malformed bodies yield no keys.
     *
     * @return list<string>
     */
    public static function objectKeys(string $messageBody): array
    {
        $payload = json_decode($messageBody, true);
        if (! is_array($payload) || ! is_array($payload['Records'] ?? null)) {
            return [];
        }

        $keys = [];
        foreach ($payload['Records'] as $record) {
            if (! is_array($record) || ! str_starts_with((string) ($record['eventName'] ?? ''), 'ObjectCreated:')) {
                continue;
            }
            $key = $record['s3']['object']['key'] ?? null;
            if (is_string($key) && $key !== '') {
                // S3 notifications URL-encode keys and encode spaces as '+'.
                $keys[] = urldecode($key);
            }
        }

        return $keys;
    }
}
