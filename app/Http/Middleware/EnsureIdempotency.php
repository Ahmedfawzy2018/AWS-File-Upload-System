<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Exceptions\IdempotencyKeyReused;
use App\Http\Exceptions\IdempotentRequestInProgress;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class EnsureIdempotency
{
    private const HEADER = 'Idempotency-Key';

    private const LOCK_SECONDS = 30;

    /** Headers worth replaying. */
    private const STORED_HEADERS = ['Content-Type', 'Location'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);
        if ($key === null) {
            return $next($request);
        }

        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $key) !== 1) {
            throw ValidationException::withMessages([
                self::HEADER => ['The Idempotency-Key header must be 1-128 characters of A-Z, a-z, 0-9, _ or -.'],
            ]);
        }

        $cacheKey = sprintf('idem:%s:%s:%s', $request->user()?->getAuthIdentifier(), $request->route()?->getName() ?? $request->path(), $key);
        $lock = Cache::lock("{$cacheKey}:lock", self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new IdempotentRequestInProgress;
        }

        try {
            $hash = $this->bodyHash($request);
            $stored = Cache::get($cacheKey);

            if (is_array($stored)) {
                if (! hash_equals((string) $stored['body_hash'], $hash)) {
                    throw new IdempotencyKeyReused;
                }

                return $this->replay($stored);
            }

            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'body_hash' => $hash,
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getContent(),
                    'headers' => $this->subset($response),
                ], (int) config('uploads.idempotency_ttl'));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function bodyHash(Request $request): string
    {
        $input = $request->isJson() ? $request->json()->all() : $request->all();
        $canonical = $this->sortKeys($input);

        $params = $request->route()?->originalParameters() ?? [];
        ksort($params);

        return hash('sha256', (string) json_encode(
            ['params' => $params === [] ? new \stdClass : $params, 'body' => $canonical === [] ? new \stdClass : $canonical],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->sortKeys(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array<string, string> */
    private function subset(Response $response): array
    {
        $headers = [];
        foreach (self::STORED_HEADERS as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        return $headers;
    }

    /** @param array<string, mixed> $stored */
    private function replay(array $stored): Response
    {
        $response = response((string) $stored['body'], (int) $stored['status'], (array) $stored['headers']);
        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }
}
