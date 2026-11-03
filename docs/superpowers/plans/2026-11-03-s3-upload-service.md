# S3 Presigned Upload Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Laravel 12 API that issues presigned S3 upload/download URLs, verifies uploads asynchronously, and enforces auth, MIME/size rules and per-user quotas.

**Architecture:** Stateless Laravel API layered HTTP → Auth (Sanctum + policies) → Domain (`app/Domain/Files`: actions, value objects, enums, rules, events) → Infrastructure (`S3StorageGateway`, Eloquent repository, SQS consumer). File bytes never touch Laravel. Upload confirmation arrives via S3→SQS events or `POST /complete`, both funnelled into one idempotent, lock-guarded `VerifyUploadJob` run by Horizon.

**Tech Stack:** PHP 8.4, Laravel 12, Sanctum, Horizon, Redis, MySQL 8, AWS SDK for PHP v3, Pest, Larastan, Pint, Docker Compose, LocalStack (S3 + SQS).

**Spec:** `docs/superpowers/specs/2026-11-03-s3-upload-service-design.md`. Read it before starting any task. Section numbers below (§n) refer to it.

## Global Constraints

- PHP 8.4, Laravel 12. `declare(strict_types=1);` in every file under `app/Domain` and `app/Infrastructure`.
- All `php` / `composer` / `vendor/bin/*` commands run inside the app container: `docker compose exec app <cmd>`. The plan writes them bare.
- Domain code (`app/Domain/**`) must not import `Aws\*`, `Illuminate\Http\*` or controllers. It depends only on `StorageGateway` / `FileRepository` contracts, Eloquent models, and Laravel facades for DB/Cache/Event.
- All limits come from `config/uploads.php` (env-overridable); no literal limits in code. Defaults (§10): max size `5368709120`, multipart threshold `104857600` (≤ threshold = single), part size `16777216`, quota `10737418240`, single PUT TTL `900`, multipart TTL `3600`, download TTL `300`, initiate rate `60`/min, other endpoints `300`/min, idempotency TTL `86400`, cleanup grace `900`.
- Allowed MIME types (§10): `image/jpeg, image/png, image/webp, image/gif, application/pdf, text/plain, text/csv, application/zip, video/mp4, application/vnd.openxmlformats-officedocument.wordprocessingml.document, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.openxmlformats-officedocument.presentationml.presentation`.
- Object key format: `users/{user_id}/{yyyy}/{mm}/{ulid}.{ext}`. The client filename never appears in a key.
- Error body shape everywhere: `{"error":{"code":"...","message":"...","details":{}}}`. Codes/status: `VALIDATION_FAILED` 422, `UNSUPPORTED_MEDIA_TYPE` 422, `FILE_TOO_LARGE` 422, `CHECKSUM_NOT_SUPPORTED` 422, `INVALID_PARTS` 422, `IDEMPOTENCY_KEY_REUSED` 422, `QUOTA_EXCEEDED` 409, `INVALID_STATE` 409, `NOT_FOUND` 404 (incl. other users' files), `UNAUTHENTICATED` 401, `RATE_LIMITED` 429.
- Never log presigned URLs.
- JSON resources are not wrapped (`JsonResource::withoutWrapping()`), except paginated lists, which use Laravel's default `data` + cursor `meta`/`links`.
- Test helpers used in the test sketches are defined in `tests/Pest.php` by the first task that uses them:
  - Task 3: `rules()`.
  - Task 5: `gateway()` and `key()`.
  - Task 6: `actingAsUser(): User`, `payload(int $size = 1024, string $mime = 'image/png'): array` and `fakeS3(): FakeStorageGateway` (the bound singleton).
  - Task 7: `pendingFile(int $size = 5, string $mime = 'text/plain', ?User $user = null): File`, `runVerify(File)` and `usageOf(int $userId): StorageUsage`.
  - Task 8: `ownPendingFile(): array{User, File}`.
  - Task 10: `fixture(string $name): string`, which reads `tests/Fixtures/`.

  Sketches marked `/* ... */` give the scenario and the expected outcome. The implementer writes the body.
- Commits: conventional commit messages, **no Co-Authored-By / Claude attribution lines**.
- Quality gate for every task before commit: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 8), `php artisan test --exclude-group=integration` all green.

## Review Focus

1. **`/complete` before the PUT has finished** (object not in S3 yet). The file must stay `pending`, the response is `202` with `status: "pending"`, and a later S3 event still verifies it. It must not be `rejected`. Test in Task 7.
2. **Late upload after expiry.** A PUT started near the end of its TTL finishes after cleanup. Cleanup only runs past `expires_at + cleanup_grace`, and an `ObjectCreated` event for an `expired` file permanently deletes the object without touching quota again. Tests in Tasks 10 and 12.
3. **Two uploads that together exceed the remaining quota.** The second gets `409 QUOTA_EXCEEDED`, and `used + reserved` never exceeds `quota`. Reservation is one atomic conditional `UPDATE`. Test in Task 4.
4. **Multipart `/complete` with missing, duplicate, out-of-range or wrong-ETag parts.** Returns `422 INVALID_PARTS`, the file stays `pending`, and a corrected retry succeeds. Tests in Task 8.
5. **Size and MIME edges.** 0 bytes → 422 `VALIDATION_FAILED`. Exactly 100 MB → single. 100 MB + 1 → multipart. Exactly 5 GB → accepted. 5 GB + 1 → `FILE_TOO_LARGE`. `Image/PNG; charset=x` normalises to `image/png`. Tests in Tasks 3 and 6.

---

### Task 1: Project scaffold, Docker, tooling, health checks

**Files:**
- Create: Laravel 12 skeleton at repo root (keep existing `README.md`, `docs/`, `.git`)
- Create: `docker-compose.yml`, `docker/php/Dockerfile`, `docker/nginx/default.conf`, `docker/mysql/init/01-testing-db.sql`
- Create: `phpstan.neon`, `pint.json`, `.github/workflows/ci.yml`
- Create: `app/Http/Controllers/HealthController.php`
- Modify: `routes/web.php` (remove welcome route), `routes/api.php`, `phpunit.xml`, `.env.example`
- Test: `tests/Feature/HealthTest.php`

**Interfaces:**
- Produces: running stack (`app`, `nginx` on :8080, `mysql`, `redis`, `localstack` on :4566). Later tasks add `horizon`, `scheduler` and `s3-consumer` services. `GET /health`, `GET /health/ready`.

- [ ] **Step 1: Scaffold.** `composer create-project laravel/laravel:^12.0 /tmp/scaffold`, then `rsync -a --exclude README.md /tmp/scaffold/ ./`. Then run `php artisan install:api` (Sanctum + `routes/api.php`) and `composer require laravel/horizon aws/aws-sdk-php`. Then `composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan` and `vendor/bin/pest --init`.
- [ ] **Step 2: Docker.** The PHP 8.4-fpm image has extensions `pdo_mysql redis pcntl bcmath intl zip`. Compose services are `app`, `nginx`, `mysql:8.0` (db `uploads`, init script creates db `testing`), `redis:7`, and `localstack/localstack:4` with `SERVICES=s3,sqs` and a mount for `docker/localstack/init/ready.d` (populated in Task 5). `.env.example` sets `DB_HOST=mysql`, `REDIS_HOST=redis`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `AWS_ENDPOINT=http://localstack:4566`, `AWS_USE_PATH_STYLE_ENDPOINT=true`, `AWS_BUCKET=uploads-local`, `AWS_DEFAULT_REGION=us-east-1`, `AWS_ACCESS_KEY_ID=test` and `AWS_SECRET_ACCESS_KEY=test`.
- [ ] **Step 3: Test config.** `phpunit.xml` sets `DB_CONNECTION=mysql`, `DB_DATABASE=testing`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync` and `SESSION_DRIVER=array`. `phpstan.neon` includes larastan at level 8 over `app/`. `pint.json` uses the `laravel` preset.
- [ ] **Step 4: Write the failing test**

```php
it('reports liveness', function () {
    $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('reports readiness of db and redis', function () {
    $this->getJson('/health/ready')->assertOk()
        ->assertJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok']]);
});

it('returns 503 when a dependency is down', function () {
    config(['database.redis.default.port' => 1]);
    $this->getJson('/health/ready')->assertStatus(503)->assertJsonPath('checks.redis', 'fail');
});
```

- [ ] **Step 5: Run.** `php artisan test tests/Feature/HealthTest.php`. Expected: FAIL (404).
- [ ] **Step 6: Implement `HealthController::live(): JsonResponse` and `HealthController::ready(): JsonResponse`.** The ready check runs `DB::select('select 1')` and `Redis::connection()->ping()`, each in a try/catch. Register both routes outside `auth:sanctum` as `/health` and `/health/ready`, at the root rather than `/api`: in `bootstrap/app.php`, use `withRouting(health: null)` and add them to `routes/web.php` without the session middleware, or to `routes/api.php` with `apiPrefix: ''` while the v1 group carries `api/v1`. Pick one approach and keep the final URLs exact.
- [ ] **Step 7: Run.** The test passes.
- [ ] **Step 8: CI.** `.github/workflows/ci.yml` uses MySQL and Redis service containers and PHP 8.4 via `shivammathur/setup-php`. It runs `composer install`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and `php artisan test --exclude-group=integration`.
- [ ] **Step 9: Commit.** `chore: scaffold Laravel 12 app with docker, tooling and health checks`

---

### Task 2: Error envelope, request id, structured logs, token auth

**Files:**
- Create: `app/Domain/Files/Exceptions/DomainError.php` (abstract)
- Create: `app/Http/Middleware/AssignRequestId.php`
- Create: `app/Http/Controllers/Api/V1/TokenController.php`, `app/Http/Requests/IssueTokenRequest.php`
- Create: `app/Console/Commands/CreateUser.php`
- Modify: `bootstrap/app.php`, `config/logging.php`, `routes/api.php`
- Test: `tests/Feature/Api/ErrorEnvelopeTest.php`, `tests/Feature/Api/TokenTest.php`, `tests/Feature/CreateUserCommandTest.php`

**Interfaces:**
- Produces: `abstract class DomainError extends \RuntimeException { abstract public function errorCode(): string; abstract public function httpStatus(): int; public function details(): array /* default [] */ }`. All domain exceptions extend it, and the handler renders it.
- Produces: the route group `Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])`, which later tasks add routes to. Final URLs are `/api/v1/...`.
- Produces: the rate limiter named `api`, set to `config('uploads.rate_limits.default')` per minute keyed by user id.
- Produces: a `request_id` key in `Illuminate\Support\Facades\Context`. Laravel copies it into queued jobs and log records.

- [ ] **Step 1: Write failing tests**

```php
// ErrorEnvelopeTest
it('renders validation errors in the envelope', function () {
    $this->postJson('/api/v1/auth/tokens', [])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['email', 'password']]]);
});
it('renders unauthenticated as 401 envelope', function () {
    $this->getJson('/api/v1/me/usage')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
});
it('echoes or generates X-Request-Id', function () {
    $this->getJson('/health', ['X-Request-Id' => 'abc-123'])->assertHeader('X-Request-Id', 'abc-123');
    expect($this->getJson('/health')->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
});

// TokenTest
it('issues a token for valid credentials', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);
    $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'secret-pass', 'device_name' => 'cli'])
        ->assertCreated()->assertJsonStructure(['token']);
});
it('rejects bad credentials with 422 VALIDATION_FAILED on email', function () { /* wrong password */ });
it('revokes the current token', function () {
    // issue token, DELETE /api/v1/auth/tokens/current with it → 204, then GET /api/v1/me/usage with it → 401
});

// CreateUserCommandTest
it('creates a user', function () {
    $this->artisan('users:create', ['email' => 'a@b.test', '--name' => 'A', '--password' => 'pw-123456'])->assertSuccessful();
    expect(User::whereEmail('a@b.test')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run.** These fail.
- [ ] **Step 3: Implement.** In `bootstrap/app.php` `withExceptions`, render JSON for `api/*` and `health*`:
  - `DomainError` maps to its own code and status.
  - `ValidationException` → `VALIDATION_FAILED`, with `details` = `$e->errors()`.
  - `AuthenticationException` → `UNAUTHENTICATED`.
  - `AuthorizationException`, `ModelNotFoundException` and `NotFoundHttpException` → `NOT_FOUND`.
  - `ThrottleRequestsException` → `RATE_LIMITED`.
  - Any other exception → `SERVER_ERROR` 500, and the message is hidden when `app.debug` is false.

  `AssignRequestId` is global middleware. It uses the incoming header when it matches `^[A-Za-z0-9-]{1,64}$`, otherwise `Str::uuid()`. It calls `Context::add('request_id', ...)` and sets the response header. Logging adds a `stderr_json` channel (Monolog `JsonFormatter`), and `LOG_STACK=stderr_json` is the default in `.env.example`.

  `TokenController::store(IssueTokenRequest): JsonResponse` returns 201 `{token}`, and `destroy(Request): Response` returns 204. Token routes are `POST /api/v1/auth/tokens` (no auth, `throttle:6,1`) and `DELETE /api/v1/auth/tokens/current` (auth). `users:create {email} {--name=} {--password=}` asks for the password when it isn't given.

  Add a placeholder `GET /api/v1/me/usage` returning 501 so the 401 test has a route. Task 11 replaces it.
- [ ] **Step 4: Run.** These pass, and the quality gate is green.
- [ ] **Step 5: Commit.** `feat: add error envelope, request ids, json logging and token auth`

---

### Task 3: Domain core: config, enums, value objects, rules

**Files:**
- Create: `config/uploads.php`
- Create: `app/Domain/Files/Enums/FileStatus.php`, `app/Domain/Files/Enums/UploadType.php`
- Create: `app/Domain/Files/ValueObjects/{MimeType,FileSize,OriginalName,ObjectKey}.php`
- Create: `app/Domain/Files/Exceptions/{UnsupportedMediaType,FileTooLarge,ChecksumNotSupported,QuotaExceeded,InvalidState,InvalidParts}.php`
- Create: `app/Domain/Files/Rules/UploadRules.php`
- Test: `tests/Unit/Domain/{FileStatusTest,MimeTypeTest,FileSizeTest,OriginalNameTest,ObjectKeyTest,UploadRulesTest}.php`

**Interfaces:**
- Produces: `config('uploads.*')` keys `allowed_mime_types` (list), `max_size_bytes`, `multipart_threshold_bytes`, `part_size_bytes`, `default_quota_bytes`, `ttl.single_put`, `ttl.multipart`, `ttl.download`, `rate_limits.initiate`, `rate_limits.default`, `idempotency_ttl`, `cleanup_grace_seconds`, `key_prefix` (`'users'`), `bucket` (env `AWS_BUCKET`), `s3_events_queue_url` (env `S3_EVENTS_QUEUE_URL`). Values per Global Constraints, each env-overridable (`UPLOADS_MAX_SIZE_BYTES`, etc.).
- Produces: `enum FileStatus: string { Pending='pending'; Uploaded='uploaded'; Ready='ready'; Rejected='rejected'; Expired='expired'; Deleted='deleted'; canTransitionTo(self): bool; assertCanTransitionTo(self): void /* throws InvalidState */ }`.
- Produces: `enum UploadType: string { Single='single'; Multipart='multipart'; }`.
- Produces: `MimeType::fromString(string): self` (lowercases, strips `;params`, trims), `->value(): string`, `->extension(): string` (fixed map, e.g. `image/jpeg→jpg`, `text/plain→txt`, docx/xlsx/pptx, falls back to `bin`).
- Produces: `FileSize::fromBytes(int): self` (throws `\InvalidArgumentException` if < 1), `->bytes(): int`, `->requiresMultipart(int $threshold): bool` (true when bytes > threshold), `->partCount(int $partSize): int` (ceil).
- Produces: `OriginalName::fromString(string): self`. It removes `/ \` and control chars, trims, truncates to 255 bytes on a UTF-8 boundary, and uses `'file'` if the result is empty. `->value(): string`.
- Produces: `ObjectKey::generate(string $prefix, int $userId, string $ulid, MimeType $type, CarbonImmutable $now): self`, `ObjectKey::fromString(string): self`, `->value(): string`.
- Produces: exceptions extending `DomainError`. Codes and statuses are listed in Global Constraints. `QuotaExceeded::details()` returns `['remaining_bytes' => int]`, and `InvalidState` takes `(FileStatus $current, string $action)`.
- Produces: `UploadRules::__construct(array $allowedMimeTypes, int $maxSizeBytes, int $multipartThreshold)`, built from config in the service provider (Task 4). `->assertAcceptable(MimeType, FileSize, ?string $checksumSha256): void` throws `UnsupportedMediaType`, `FileTooLarge`, or `ChecksumNotSupported` when a checksum is given and the size requires multipart.

- [ ] **Step 1: Write failing tests** (key cases; one `it` each)

```php
// FileStatusTest — allowed transitions only:
// pending→uploaded|rejected|expired; uploaded→ready|rejected; ready→deleted; rejected→deleted; expired→deleted
it('allows only the specified transitions', function (FileStatus $from, FileStatus $to, bool $ok) {
    expect($from->canTransitionTo($to))->toBe($ok);
})->with([
    [FileStatus::Pending, FileStatus::Uploaded, true], [FileStatus::Pending, FileStatus::Ready, false],
    [FileStatus::Uploaded, FileStatus::Ready, true], [FileStatus::Ready, FileStatus::Pending, false],
    [FileStatus::Ready, FileStatus::Deleted, true], [FileStatus::Expired, FileStatus::Uploaded, false],
]);
it('throws InvalidState on illegal transition', fn () => FileStatus::Ready->assertCanTransitionTo(FileStatus::Pending))
    ->throws(InvalidState::class);

// MimeTypeTest
it('normalises case and parameters', fn () => expect(MimeType::fromString(' Image/PNG; charset=x ')->value())->toBe('image/png'));

// FileSizeTest
it('rejects zero bytes', fn () => FileSize::fromBytes(0))->throws(InvalidArgumentException::class);
it('treats exactly the threshold as single', fn () => expect(FileSize::fromBytes(104857600)->requiresMultipart(104857600))->toBeFalse());
it('treats threshold + 1 as multipart', fn () => expect(FileSize::fromBytes(104857601)->requiresMultipart(104857600))->toBeTrue());
it('computes part count', fn () => expect(FileSize::fromBytes(16777216 * 2 + 1)->partCount(16777216))->toBe(3));

// OriginalNameTest
it('strips path separators and control chars', fn () => expect(OriginalName::fromString("../../etc/pa\x00sswd")->value())->toBe('....etcpasswd'));
it('falls back to file when empty', fn () => expect(OriginalName::fromString(" /\\ ")->value())->toBe('file'));
it('truncates to 255 bytes without splitting utf-8', function () {
    $v = OriginalName::fromString(str_repeat('é', 200))->value();
    expect(strlen($v))->toBeLessThanOrEqual(255)->and(mb_check_encoding($v, 'UTF-8'))->toBeTrue();
});

// ObjectKeyTest
it('builds the key from server data only', function () {
    $k = ObjectKey::generate('users', 42, '01JB0000000000000000000000', MimeType::fromString('image/jpeg'), CarbonImmutable::parse('2026-03-09'));
    expect($k->value())->toBe('users/42/2026/03/01JB0000000000000000000000.jpg');
});

// UploadRulesTest (rules built with spec defaults)
it('accepts exactly max size', fn () => rules()->assertAcceptable(MimeType::fromString('video/mp4'), FileSize::fromBytes(5368709120), null))->throwsNoExceptions();
it('rejects max + 1', fn () => rules()->assertAcceptable(MimeType::fromString('video/mp4'), FileSize::fromBytes(5368709121), null))->throws(FileTooLarge::class);
it('rejects disallowed mime', fn () => rules()->assertAcceptable(MimeType::fromString('application/x-msdownload'), FileSize::fromBytes(10), null))->throws(UnsupportedMediaType::class);
it('rejects checksum on multipart sizes', fn () => rules()->assertAcceptable(MimeType::fromString('video/mp4'), FileSize::fromBytes(104857601), str_repeat('a', 64)))->throws(ChecksumNotSupported::class);
```

- [ ] **Step 2: Run.** `php artisan test tests/Unit/Domain` fails.
- [ ] **Step 3: Implement** the files listed above to the Interfaces signatures. Value objects are `final readonly` classes.
- [ ] **Step 4: Run.** These pass, and the quality gate is green.
- [ ] **Step 5: Commit.** `feat(domain): add upload config, status enum, value objects and rules`

---

### Task 4: Persistence: migrations, models, repository, quota ledger

**Files:**
- Create: `database/migrations/*_create_files_table.php`, `*_create_user_storage_usage_table.php`
- Create: `app/Models/File.php`, `app/Models/UserStorageUsage.php`, `database/factories/FileFactory.php`
- Create: `app/Domain/Files/Contracts/FileRepository.php`, `app/Infrastructure/Persistence/EloquentFileRepository.php`
- Create: `app/Domain/Files/Services/QuotaLedger.php`, `app/Domain/Files/DTOs/StorageUsage.php`
- Create: `app/Providers/FilesServiceProvider.php` (register in `bootstrap/providers.php`)
- Test: `tests/Feature/Domain/QuotaLedgerTest.php`, `tests/Feature/Domain/FileRepositoryTest.php`

**Interfaces:**
- Produces the `files` table as in §7, plus columns `version_id` varchar(255) nullable and `multipart_completed_at` timestamp nullable. The `id` is a ULID (`HasUlids`) and `object_key` is unique. Indexes are `(user_id, status, created_at)` and `(status, expires_at)`. Soft deletes are on.
- Produces the `user_storage_usage` table: `user_id` (PK, FK cascade), `used_bytes`, `reserved_bytes` and `quota_bytes`, all unsigned bigint, plus `updated_at`.
- Produces `File` model casts: `status → FileStatus`, `upload_type → UploadType`, timestamps → `immutable_datetime`. Methods: `objectKey(): ObjectKey`; `transitionTo(FileStatus $to): void` (calls `assertCanTransitionTo`, sets status, then sets `uploaded_at`/`ready_at` when relevant, without saving).
- Produces `interface FileRepository { findByObjectKey(string $key): ?File; stalePending(CarbonImmutable $cutoff, int $limit): Collection /* File[], status pending, expires_at < cutoff */; paginateForUser(int $userId, ?FileStatus $status, int $perPage): CursorPaginator /* newest first, excludes soft-deleted */ }`.
- Produces `QuotaLedger` (constructor `int $defaultQuotaBytes`) with methods:
  - `reserve(int $userId, int $bytes): void`, which throws `QuotaExceeded`.
  - `commit(int $userId, int $bytes): void`, which moves bytes from reserved to used.
  - `release(int $userId, int $bytes): void`, which takes bytes off reserved.
  - `releaseUsed(int $userId, int $bytes): void`, which takes bytes off used.
  - `usage(int $userId): StorageUsage`, which returns `final readonly class StorageUsage(int $usedBytes, int $reservedBytes, int $quotaBytes)`.
- Produces `FilesServiceProvider`, which binds `FileRepository`, `UploadRules` (from config) and `QuotaLedger` (from config), and later `StorageGateway` (Task 5).

- [ ] **Step 1: Write failing tests**

```php
it('reserves within quota and reports usage', function () {
    config(['uploads.default_quota_bytes' => 100]);
    $u = User::factory()->create();
    app(QuotaLedger::class)->reserve($u->id, 60);
    expect(app(QuotaLedger::class)->usage($u->id))->toEqual(new StorageUsage(0, 60, 100));
});
it('rejects a second reservation that would exceed quota', function () {   // Review Focus #3
    config(['uploads.default_quota_bytes' => 100]);
    $u = User::factory()->create(); $l = app(QuotaLedger::class);
    $l->reserve($u->id, 60);
    expect(fn () => $l->reserve($u->id, 41))->toThrow(QuotaExceeded::class);
    expect($l->usage($u->id)->reservedBytes)->toBe(60);
});
it('allows filling quota exactly', fn () => /* reserve 60 then 40 → no throw */);
it('commits reserved to used and releases', function () {
    // reserve 50, commit 50 → (50,0); reserve 10, release 10 → (50,0); releaseUsed 50 → (0,0)
});
it('never goes negative on release', fn () => /* release 10 on fresh user → reserved 0 */);

it('finds stale pending files only', function () {
    // pending expired 1h ago → included; pending expiring in future → excluded; ready expired → excluded
});
it('paginates own files newest first and filters by status', function () { /* two users, assert isolation + filter */ });
```

- [ ] **Step 2: Run.** These fail.
- [ ] **Step 3: Implement.** `reserve` first runs `insertOrIgnore` on the usage row (quota = default). It then runs one atomic statement:
  `UPDATE user_storage_usage SET reserved_bytes = reserved_bytes + ? WHERE user_id = ? AND used_bytes + reserved_bytes + ? <= quota_bytes`.
  If that affects 0 rows, throw `QuotaExceeded` with the remaining bytes. Releases use `GREATEST(x - ?, 0)`, and `commit` is one `UPDATE` that changes both columns.
- [ ] **Step 4: Run.** These pass, and the quality gate is green.
- [ ] **Step 5: Commit.** `feat: add files schema, models, repository and quota ledger`

---

### Task 5: Storage gateway (contract, fake, S3 adapter, LocalStack bootstrap)

**Files:**
- Create: `app/Domain/Files/Contracts/StorageGateway.php`
- Create: `app/Domain/Files/DTOs/{PresignedRequest,ObjectMetadata}.php`
- Create: `app/Domain/Files/Exceptions/StorageError.php` (not a `DomainError`, so it renders as a 500)
- Create: `app/Infrastructure/Storage/S3StorageGateway.php`
- Create: `tests/Support/FakeStorageGateway.php`
- Create: `docker/localstack/init/ready.d/init-aws.sh`
- Test: `tests/Unit/Support/FakeStorageGatewayTest.php`, `tests/Integration/S3StorageGatewayTest.php` (`->group('integration')`)

**Interfaces:**
- Produces `StorageGateway` from §6.2 with these changes:
  - `presignPut(ObjectKey $key, MimeType $type, FileSize $size, int $ttlSeconds, ?string $checksumSha256Hex = null): PresignedRequest`
  - `completeMultipart(ObjectKey $key, string $uploadId, array $parts): void`, where `$parts` is `array<int,string>` (partNumber => etag). It throws `InvalidParts` when S3 answers `InvalidPart`, `InvalidPartOrder`, `EntityTooSmall` or `NoSuchUpload`.
  - an added `deleteVersion(ObjectKey $key, string $versionId): void`.
  - `presignGet` and `presignPart` as in the spec.
  - `head` returns `?ObjectMetadata`, which is null on 404.
- Produces `final readonly class PresignedRequest(string $method, string $url, array $headers, CarbonImmutable $expiresAt)` and `final readonly class ObjectMetadata(int $sizeBytes, string $contentType, string $etag, ?string $versionId)`.
- Produces `FakeStorageGateway implements StorageGateway`. It keeps state in memory and has test helpers `putObject(string $key, int $size, string $type, ?string $versionId = 'v1')`, `objects(): array`, `deletedVersions(): array`, `aborted(): array` and `failCompleteWith(DomainError $e)`. Its presigned URLs look like `https://fake-s3.test/{key}?sig=...`.
- The S3 client comes from config `filesystems.disks.s3` (key, secret, region, endpoint, `use_path_style_endpoint`). Bind it in `FilesServiceProvider` as `StorageGateway::class → S3StorageGateway`.

- [ ] **Step 1: `init-aws.sh`** (runs with `awslocal`):
  - Create bucket `uploads-local` with versioning enabled.
  - Set CORS to allow `PUT, GET` from `*` (local only), with headers `*` and exposed `ETag`.
  - Add lifecycle rules: abort incomplete multipart after 2 days, expire noncurrent versions after 7 days, and expire delete markers.
  - Create queue `s3-events-dlq`, then queue `s3-events` with a redrive policy (`maxReceiveCount` 5).
  - Add the bucket notification `s3:ObjectCreated:*` with prefix `users/` → `s3-events`.
  - Echo the queue URL. `.env.example` sets `S3_EVENTS_QUEUE_URL=http://localstack:4566/000000000000/s3-events`.
- [ ] **Step 2: Write failing integration tests** (`uses()->group('integration')`, real HTTP via `Http::`)

```php
it('presigned PUT uploads, HEAD reports metadata', function () {
    $req = gateway()->presignPut($key = key(), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900);
    Http::withHeaders($req->headers)->withBody('hello', 'text/plain')->put($req->url)->throw();
    expect(gateway()->head($key))->sizeBytes->toBe(5)->contentType->toBe('text/plain')->versionId->not->toBeNull();
});
it('signed headers include content-type, content-length and if-none-match', function () {
    $req = gateway()->presignPut(key(), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900);
    expect(array_change_key_case($req->headers))->toHaveKeys(['content-type', 'content-length', 'if-none-match']);
});
it('rejects a second PUT to the same key', function () {
    // PUT once OK; PUT again with same URL+headers → 412. If LocalStack returns 200, STOP and report — do not weaken the test.
});
it('rejects a PUT whose body length differs from the signed size', fn () => /* sign 5, send 6 bytes → 4xx */);
it('head returns null for a missing key', fn () => expect(gateway()->head(key()))->toBeNull());
it('multipart: create, presign parts, complete', function () {
    // 2 parts: 5 MiB + 1 byte; collect ETag headers; completeMultipart; head size = 5 MiB + 1
});
it('completeMultipart with a bogus etag throws InvalidParts', fn () => /* ... */)->throws(InvalidParts::class);
it('presignGet sets content-disposition with the original name', fn () => /* GET url → header contains filename="report.pdf" */);
it('deleteVersion removes the version permanently', fn () => /* head null after */);
```

  Also write `FakeStorageGatewayTest`, which mirrors `head`, `presignPut` (headers present) and the `failCompleteWith` behaviour.
- [ ] **Step 3: Run.** `docker compose up -d localstack && php artisan test --group=integration` fails.
- [ ] **Step 4: Implement `S3StorageGateway`.**
  - **presignPut:** `createPresignedRequest` on `PutObject` with `ContentType`, `ContentLength`, `IfNoneMatch => '*'`, and `ChecksumSHA256 => base64_encode(hex2bin($hex))` when a checksum is given. The returned `headers` are exactly the signed headers the client must send (`Content-Type`, `Content-Length`, `If-None-Match`, and `x-amz-checksum-sha256` if used), with `Host` left out.
  - **completeMultipart:** sends `IfNoneMatch => '*'`.
  - **presignGet:** `ResponseContentDisposition = attachment; filename="<ascii-fallback>"; filename*=UTF-8''<rawurlencoded>`.
  - **head:** catches `S3Exception` with status 404 and returns null. Any other AWS error is wrapped in `StorageError`.
- [ ] **Step 5: Run.** The integration and unit tests pass, and the quality gate is green.
- [ ] **Step 6: Commit.** `feat(storage): add StorageGateway contract, S3 adapter, fake and localstack bootstrap`

---

### Task 6: Initiate upload (`POST /api/v1/uploads`)

**Files:**
- Create: `app/Domain/Files/DTOs/{InitiateUploadData,InitiatedUpload}.php`
- Create: `app/Domain/Files/Actions/InitiateUpload.php`
- Create: `app/Http/Requests/InitiateUploadRequest.php`, `app/Http/Controllers/Api/V1/UploadController.php`
- Create: `app/Http/Resources/{FileResource,InitiatedUploadResource}.php`
- Modify: `routes/api.php`, `app/Providers/AppServiceProvider.php` (rate limiter `uploads-initiate`, `JsonResource::withoutWrapping()`)
- Test: `tests/Feature/Api/InitiateUploadTest.php`, `tests/Pest.php` (bind `FakeStorageGateway` for Feature tests, plus `actingAsUser()` helper)

**Interfaces:**
- Consumes: `UploadRules`, `QuotaLedger`, `StorageGateway`, the value objects, and `File`.
- Produces: `final readonly class InitiateUploadData(int $userId, string $filename, string $mimeType, int $sizeBytes, ?string $checksumSha256)` with `static fromRequest(InitiateUploadRequest): self`.
- Produces: `final readonly class InitiatedUpload(File $file, ?PresignedRequest $put, array $parts /* list<array{part_number:int, url:string}> */, ?int $partSize, CarbonImmutable $expiresAt)`.
- Produces: `InitiateUpload::handle(InitiateUploadData): InitiatedUpload`.
- Produces: `FileResource` JSON fields `id, original_name, mime_type, size_bytes, status, upload_type, failure_reason, created_at, uploaded_at, ready_at`.
- Produces: the response shape below. Single upload: `201 {id, status:"pending", upload:{type:"single", method:"PUT", url, headers:{...}, expires_at}}`. Multipart upload: `201 {id, status:"pending", upload:{type:"multipart", part_size, parts:[{part_number,url}], expires_at}}`.

- [ ] **Step 1: Write failing tests** (with `FakeStorageGateway` bound)

```php
it('initiates a single upload', function () {
    actingAsUser();
    $r = $this->postJson('/api/v1/uploads', ['filename' => 'a.png', 'mime_type' => 'image/png', 'size_bytes' => 1024])
        ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('upload.type', 'single')
        ->assertJsonPath('upload.method', 'PUT')->assertJsonStructure(['id', 'upload' => ['url', 'headers', 'expires_at']]);
    $file = File::find($r->json('id'));
    expect($file->object_key)->toMatch('#^users/\d+/\d{4}/\d{2}/[0-9A-Z]{26}\.png$#')
        ->and($file->expires_at->diffInSeconds(now()->addSeconds(900)))->toBeLessThan(5);
    expect(app(QuotaLedger::class)->usage($file->user_id)->reservedBytes)->toBe(1024);
});
it('uses single at exactly 100 MB and multipart above', function () {  // Review Focus #5
    actingAsUser();
    $this->postJson('/api/v1/uploads', payload(size: 104857600))->assertJsonPath('upload.type', 'single');
    $this->postJson('/api/v1/uploads', payload(size: 104857601))->assertJsonPath('upload.type', 'multipart')
        ->assertJsonPath('upload.part_size', 16777216)->assertJsonCount(7, 'upload.parts');
});
it('rejects zero bytes', fn () => /* size 0 → 422 VALIDATION_FAILED details.size_bytes */);
it('rejects over max size', fn () => /* 5368709121 → 422 FILE_TOO_LARGE */);
it('accepts exactly max size', fn () => /* 5368709120 → 201 multipart */);
it('rejects disallowed mime', fn () => /* application/x-msdownload → 422 UNSUPPORTED_MEDIA_TYPE */);
it('normalises mime params', fn () => /* 'Image/PNG; charset=x' → stored 'image/png' */);
it('rejects checksum on multipart', fn () => /* 422 CHECKSUM_NOT_SUPPORTED */);
it('validates checksum format', fn () => /* 'xyz' → 422 VALIDATION_FAILED */);
it('rejects when quota exceeded and creates no row', fn () => /* quota 1000, size 1001 → 409 QUOTA_EXCEEDED, File::count()==0 */);
it('never puts the client filename in the key', fn () => /* filename '../../x.png' → key has no '..' and original_name sanitised */);
it('rate limits initiate to 60/min', fn () => /* 61st → 429 RATE_LIMITED */);
it('requires auth', fn () => /* 401 */);
```

- [ ] **Step 2: Run.** These fail.
- [ ] **Step 3: Implement.**
  - **Validation (FormRequest):** `filename` is required|string|max:1024. `mime_type` is required|string|max:255. `size_bytes` is required|integer|min:1. `checksum_sha256` is nullable|regex `/^[a-f0-9]{64}$/i`. Business rules (MIME, max size, checksum-on-multipart) live in `UploadRules`, not the FormRequest.
  - **Action order:**
    1. Build the value objects and call `rules->assertAcceptable`.
    2. In a `DB::transaction`, call `ledger->reserve`, create the `File` (pending, key generated, `expires_at = now + ttl`), and, for multipart, call `createMultipart` and store `multipart_upload_id`.
    3. Presign (PUT, or every part with `presignPart`).
  - **If the multipart create call fails:** the transaction rolls back, which also undoes the reservation.
  - **Route:** `POST /uploads` with `throttle:uploads-initiate` (60/min per user).
- [ ] **Step 4: Run.** These pass, and the quality gate is green.
- [ ] **Step 5: Commit.** `feat: initiate single and multipart uploads`

---

### Task 7: Verification job and domain events

**Files:**
- Create: `app/Domain/Files/Actions/VerifyUpload.php`
- Create: `app/Jobs/VerifyUploadJob.php`
- Create: `app/Domain/Files/Events/{FileUploaded,FileReady,FileRejected,FileDeleted}.php`
- Test: `tests/Feature/Jobs/VerifyUploadJobTest.php`

**Interfaces:**
- Produces: `VerifyUpload::handle(string $fileId): void`.
- Produces: `VerifyUploadJob(string $fileId)`. It implements `ShouldQueue`, runs on queue `uploads` with `tries = 3` and `backoff = [5, 30, 120]`, and wraps the action in `Cache::lock("files:{$fileId}:verify", 60)->block(10)`.
- Produces: the events, each with `public readonly string $fileId`. `FileRejected` also has `string $reason`.

**Behaviour:**
- If the file is not `pending`, the job does nothing (idempotent).
- If `head()` returns null, the file stays `pending` and the job logs at info level and returns without retrying (Review Focus #1).
- If size ≠ `size_bytes` or content type ≠ `mime_type`: `deleteVersion` (or `delete` if `versionId` is null), status `rejected`, `failure_reason` is `size_mismatch` or `content_type_mismatch`, `ledger->release`, then fire `FileRejected`.
- Otherwise: store `etag` and `version_id`, go `uploaded` and fire `FileUploaded`, then go `ready`, call `ledger->commit` and fire `FileReady`. Save with `ready_at` set.
- All of this runs inside a DB transaction. Events are dispatched after commit (`ShouldDispatchAfterCommit`).

- [ ] **Step 1: Write failing tests**

```php
it('marks a matching object ready and commits quota', function () {
    $file = pendingFile(size: 5, mime: 'text/plain'); fakeS3()->putObject($file->object_key, 5, 'text/plain', 'v9');
    Event::fake([FileUploaded::class, FileReady::class]);
    (new VerifyUploadJob($file->id))->handle(app(VerifyUpload::class));
    expect($file->fresh())->status->toBe(FileStatus::Ready)->version_id->toBe('v9')->ready_at->not->toBeNull();
    expect(usageOf($file->user_id))->usedBytes->toBe(5)->reservedBytes->toBe(0);
    Event::assertDispatched(FileReady::class);
});
it('leaves the file pending when the object is not there yet', function () {   // Review Focus #1
    $file = pendingFile(); runVerify($file);
    expect($file->fresh()->status)->toBe(FileStatus::Pending);
});
it('rejects a size mismatch, deletes that version and releases quota', function () {
    $file = pendingFile(size: 5); fakeS3()->putObject($file->object_key, 6, 'text/plain', 'v1'); runVerify($file);
    expect($file->fresh())->status->toBe(FileStatus::Rejected)->failure_reason->toBe('size_mismatch');
    expect(fakeS3()->deletedVersions())->toContain([$file->object_key, 'v1']);
    expect(usageOf($file->user_id)->reservedBytes)->toBe(0);
});
it('rejects a content-type mismatch', fn () => /* failure_reason content_type_mismatch */);
it('is a no-op when run twice', function () {
    // run twice → still ready, usedBytes counted once, FileReady dispatched once
});
it('is queued on the uploads queue', fn () => expect((new VerifyUploadJob('x'))->queue)->toBe('uploads'));
```

- [ ] **Step 2: Run.** These fail.
- [ ] **Step 3: Implement** as described under Behaviour.
- [ ] **Step 4: Run.** These pass, and the quality gate is green.
- [ ] **Step 5: Commit.** `feat: verify uploads asynchronously with idempotent locked job`

---

### Task 8: Complete, re-sign parts, abort

**Files:**
- Create: `app/Domain/Files/Actions/{CompleteUpload,ResignParts,AbortUpload}.php`, `app/Domain/Files/DTOs/CompleteUploadData.php`
- Create: `app/Http/Requests/{CompleteUploadRequest,ResignPartsRequest}.php`, `app/Policies/FilePolicy.php`
- Modify: `UploadController.php`, `routes/api.php`
- Test: `tests/Feature/Api/CompleteUploadTest.php`, `tests/Feature/Api/ResignPartsTest.php`, `tests/Feature/Api/AbortUploadTest.php`

**Interfaces:**
- Consumes: `VerifyUploadJob(string $fileId)`, `StorageGateway`, `QuotaLedger`.
- Produces: `FilePolicy` with `view(User, File): bool`, `update(User, File): bool` and `delete(User, File): bool`, all `$file->user_id === $user->id`. Controllers call `Gate::authorize`, and a denial renders as 404 (Task 2 handler).
- Produces: `final readonly class CompleteUploadData(File $file, ?array $parts /* array<int,string> */)` and `CompleteUpload::handle(CompleteUploadData): File`.
- Produces: `ResignParts::handle(File $file, array $partNumbers): array`, which returns `list<array{part_number:int,url:string}>`.
- Produces: `AbortUpload::handle(File $file): File`.
- Routes: `POST /uploads/{file}/complete` → 202 + `FileResource`, `POST /uploads/{file}/parts` → 200 `{parts:[...], expires_at}`, `POST /uploads/{file}/abort` → 200 + `FileResource`.

**Behaviour:**
- **Complete, file `uploaded` or `ready`:** return 202 with the current state. No S3 call and no job.
- **Complete, file `rejected`, `expired` or `deleted`:** `InvalidState`.
- **Complete, single upload:** dispatch the job and return 202.
- **Complete, multipart:**
  - `parts` is required. The keys must be exactly `1..partCount` (no gaps or duplicates) and each ETag a non-empty string; otherwise `InvalidParts` with details naming the problem.
  - If `multipart_completed_at` is null, call `completeMultipart` and set it. When S3 returns `InvalidParts`, the error propagates and the file stays `pending` (Review Focus #4). Then dispatch the job.
- **ResignParts:** pending multipart only, otherwise `InvalidState`. Part numbers must be in `1..partCount`, otherwise `INVALID_PARTS`. TTL is `ttl.multipart`.
- **Abort:** pending only, otherwise `InvalidState`. For multipart that isn't completed, call `abortMultipart`. Status becomes `expired`, `failure_reason` becomes `aborted`, and the reserved bytes are released.

- [ ] **Step 1: Write failing tests**

```php
it('dispatches verification for a single upload and returns 202 pending', function () {   // Review Focus #1
    Queue::fake(); [$user, $file] = ownPendingFile();
    $this->postJson("/api/v1/uploads/{$file->id}/complete")->assertStatus(202)->assertJsonPath('status', 'pending');
    Queue::assertPushedOn('uploads', VerifyUploadJob::class);
});
it('returns 404 for another user\'s upload', fn () => /* NOT_FOUND */);
it('returns current state without work when already ready', fn () => /* Queue::assertNothingPushed */);
it('returns 409 INVALID_STATE for expired uploads', fn () => /* ... */);
it('completes multipart with the provided parts', function () {
    // multipart file with partCount 3, parts {1:e1,2:e2,3:e3} → fake records complete, multipart_completed_at set, job pushed
});
it('rejects missing, duplicate or out-of-range parts with 422 INVALID_PARTS', function (array $parts) {  // Review Focus #4
    // file stays pending; fake complete not called
})->with([[[1 => 'a', 2 => 'b']], [[1 => 'a', 2 => 'b', 4 => 'c']], [[0 => 'a', 1 => 'b', 2 => 'c']]]);
it('surfaces S3 InvalidPart as 422 and allows a corrected retry', function () {
    // fakeS3()->failCompleteWith(new InvalidParts(...)) → 422, still pending, multipart_completed_at null; reset fail → retry 202
});
it('does not call S3 complete twice', fn () => /* second /complete while pending after S3 complete → no second complete call */);

// ResignPartsTest
it('re-signs requested parts', fn () => /* part_numbers [2,3] → 2 urls */);
it('rejects single uploads with 409', fn () => /* ... */);

// AbortUploadTest
it('aborts a pending multipart upload and releases quota', fn () => /* fake aborted contains uploadId, status expired, reserved 0 */);
it('refuses to abort a ready file', fn () => /* 409 */);
```

- [ ] **Step 2: Run.** These fail.
- [ ] **Step 3: Implement** as described under Behaviour. `CompleteUploadRequest` validates `parts` as an optional array of `{part_number:int>=1, etag:string}` and converts it to the `array<int,string>` map. Duplicate part numbers are caught while converting and become `InvalidParts`.
- [ ] **Step 4: Run.** These pass, and the quality gate is green.
- [ ] **Step 5: Commit.** `feat: complete, re-sign and abort uploads`

---

### Task 9: Idempotency-Key middleware

**Files:**
- Create: `app/Http/Middleware/EnsureIdempotency.php` (alias `idempotent`)
- Modify: `bootstrap/app.php` (alias), `routes/api.php` (apply to `POST /uploads` and `POST /uploads/{file}/complete`)
- Test: `tests/Feature/Api/IdempotencyTest.php`

**Interfaces:**
- When the `Idempotency-Key` header is absent, the middleware passes the request through.
- When it's present, the middleware must match `^[A-Za-z0-9_-]{1,128}$` (otherwise 422 `VALIDATION_FAILED`). Its cache key is `idem:{user_id}:{route_name}:{key}`, and it stores `{body_hash, status, body, headers subset}` for `config('uploads.idempotency_ttl')`. The `body_hash` is the sha256 of the canonical JSON request body.

**Behaviour:**
- **Same key, same body:** replay the stored response and add the header `Idempotent-Replayed: true`.
- **Same key, different body:** 422 `IDEMPOTENCY_KEY_REUSED`.
- **Concurrent duplicate:** guarded by `Cache::lock("{cachekey}:lock", 30)`. If the lock isn't acquired, return 409 `INVALID_STATE` with message "request in progress".
- Only responses with status < 500 are stored.

- [ ] **Step 1: Write failing tests**

```php
it('replays the original initiate response for a repeated key', function () {
    actingAsUser(); $h = ['Idempotency-Key' => 'k1'];
    $a = $this->postJson('/api/v1/uploads', payload(), $h)->assertCreated();
    $b = $this->postJson('/api/v1/uploads', payload(), $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect($b->json('id'))->toBe($a->json('id'))->and(File::count())->toBe(1);
});
it('rejects key reuse with a different body', fn () => /* 422 IDEMPOTENCY_KEY_REUSED */);
it('scopes keys per user', fn () => /* same key, two users → two files */);
it('does not store 5xx responses', fn () => /* ... */);
```

- [ ] **Step 2: Run.** These fail.
- [ ] **Step 3: Implement** as described under Behaviour.
- [ ] **Step 4: Run.** These pass, and the quality gate is green.
- [ ] **Step 5: Commit.** `feat: support Idempotency-Key on initiate and complete`

---

### Task 10: S3 event consumer

**Files:**
- Create: `app/Infrastructure/Queue/S3EventParser.php`
- Create: `app/Infrastructure/Queue/S3EventConsumer.php`, `app/Console/Commands/ConsumeS3Events.php`
- Create: `app/Jobs/DeleteObjectJob.php`
- Test: `tests/Unit/Infrastructure/S3EventParserTest.php`, `tests/Feature/S3EventConsumerTest.php`

**Interfaces:**
- Produces: `S3EventParser::objectKeys(string $messageBody): list<string>`. It returns `[]` for `s3:TestEvent` and for malformed JSON. Each key is URL-decoded, with `+` read as a space (S3 notification encoding). Only `ObjectCreated:*` records count.
- Produces: `S3EventConsumer::handleKey(string $objectKey): void` (testable without SQS). It looks the file up with `FileRepository::findByObjectKey`:
  - **Not found:** log a warning.
  - **`pending`:** dispatch `VerifyUploadJob`.
  - **`expired`:** dispatch `DeleteObjectJob($key, permanent: true)` (Review Focus #2). Quota is not touched.
  - **`uploaded`, `ready`, `rejected` or `deleted`:** log a warning `s3_event.unexpected_object_created` with file id and status.
- Produces: `DeleteObjectJob(string $objectKey, bool $permanent)` on queue `maintenance`. When `$permanent`, it calls `head()`: a `versionId` means `deleteVersion`, a null head means no-op, and an unversioned object means `delete`. Otherwise it calls `delete()`.
- Produces: the command `uploads:consume-s3-events {--once}`. It long-polls `config('uploads.s3_events_queue_url')` with `WaitTimeSeconds=20` and `MaxNumberOfMessages=10`. For each message it calls `handleKey` for every key and then deletes the message. Messages that throw are not deleted, so SQS redelivery sends them to the DLQ after 5 tries. It traps SIGTERM and finishes the current batch before exiting.

- [ ] **Step 1: Write failing tests**

```php
// S3EventParserTest
it('extracts decoded keys from ObjectCreated records', fn () =>
    expect(S3EventParser::objectKeys(fixture('s3-put.json')))->toBe(['users/1/2026/11/01JB.png']));
it('decodes + and %xx', fn () => /* 'users/1/a+b%2Bc.txt' → 'users/1/a b+c.txt' */);
it('ignores test events and garbage', fn () => expect(S3EventParser::objectKeys('{"Event":"s3:TestEvent"}'))->toBe([])
    ->and(S3EventParser::objectKeys('not json'))->toBe([]));

// S3EventConsumerTest
it('dispatches verification for pending files', fn () => /* Queue::assertPushed(VerifyUploadJob) */);
it('permanently deletes objects that arrive after expiry without touching quota', function () {  // Review Focus #2
    // expired file, usage snapshot; handleKey → DeleteObjectJob(permanent: true) pushed; usage unchanged
});
it('logs and ignores unexpected events for ready files', fn () => /* Log::spy, nothing pushed */);
it('ignores unknown keys', fn () => /* nothing pushed */);
```

- [ ] **Step 2: Run.** These fail.
- [ ] **Step 3: Implement.** Add the `s3-consumer` service to `docker-compose.yml` (command `php artisan uploads:consume-s3-events`, restart `unless-stopped`).
- [ ] **Step 4: Run.** These pass, and the quality gate is green.
- [ ] **Step 5: Commit.** `feat: consume S3 ObjectCreated events from SQS`

---

### Task 11: File endpoints: list, show, download, delete, usage

**Files:**
- Create: `app/Domain/Files/Actions/{GetDownloadUrl,DeleteFile}.php`
- Create: `app/Http/Controllers/Api/V1/{FileController,UsageController}.php`, `app/Http/Requests/ListFilesRequest.php`
- Modify: `routes/api.php` (replace the Task 2 `/me/usage` placeholder)
- Test: `tests/Feature/Api/FilesTest.php`, `tests/Feature/Api/UsageTest.php`

**Interfaces:**
- Consumes: `FilePolicy`, `FileRepository::paginateForUser`, `StorageGateway::presignGet`, `QuotaLedger`, `DeleteObjectJob`.
- Produces: `GetDownloadUrl::handle(File $file): PresignedRequest`. It works only for `ready` files (otherwise `InvalidState`) and uses `ttl.download`.
- Produces: `DeleteFile::handle(File $file): void` with this behaviour:
  - **`ready`:** status becomes `deleted`, the row is soft-deleted, `ledger->releaseUsed`, and `DeleteObjectJob($key, permanent: false)` is dispatched. A user delete only adds a delete marker, so the object is kept for 7 days (§8.4).
  - **`rejected` or `expired`:** status becomes `deleted` and the row is soft-deleted. No quota change and no S3 call.
  - **`pending` or `uploaded`:** `InvalidState`. Use abort for pending uploads.
- Routes:
  - `GET /files?status=&per_page=` (default 50, max 100) returns cursor-paginated `FileResource`.
  - `GET /files/{file}` returns `FileResource`.
  - `GET /files/{file}/download` returns `{url, expires_at}`.
  - `DELETE /files/{file}` returns 204.
  - `GET /me/usage` returns `{used_bytes, reserved_bytes, quota_bytes}`.
  - Soft-deleted files resolve to 404, which is the default for route binding.

- [ ] **Step 1: Write failing tests**

```php
it('lists only own files, newest first, filterable by status', fn () => /* ... */);
it('caps per_page at 100', fn () => /* per_page=500 → 422 VALIDATION_FAILED */);
it('shows own file and 404s for others', fn () => /* ... */);
it('returns a download url for ready files only', function () {
    // ready → 200 {url, expires_at ≈ now+300}; pending → 409 INVALID_STATE
});
it('deletes a ready file: soft delete, quota released, object delete queued', function () {
    // Queue::fake; DELETE → 204; File::withTrashed status deleted; usedBytes 0; DeleteObjectJob pushed with permanent false
});
it('refuses to delete a pending upload', fn () => /* 409 */);
it('reports usage', fn () => /* after one ready 5-byte file and one pending 7-byte → {5, 7, 10737418240} */);
```

- [ ] **Step 2: Run.** These fail.
- [ ] **Step 3: Implement** as described under Interfaces.
- [ ] **Step 4: Run.** These pass, and the quality gate is green.
- [ ] **Step 5: Commit.** `feat: list, show, download and delete files; usage endpoint`

---

### Task 12: Stale upload cleanup and scheduler

**Files:**
- Create: `app/Domain/Files/Actions/ExpireStaleUploads.php`, `app/Jobs/CleanupStaleUploadsJob.php`
- Modify: `routes/console.php` (schedule), `docker-compose.yml` (`scheduler` service: `php artisan schedule:work`)
- Test: `tests/Feature/Jobs/CleanupStaleUploadsTest.php`

**Interfaces:**
- Produces: `ExpireStaleUploads::handle(CarbonImmutable $now, int $batchSize = 200): int`, which returns the number of files it expired.
  - **Cutoff:** `$now - cleanup_grace_seconds`. It takes stale files from `FileRepository::stalePending(cutoff, batchSize)`.
  - **Per file**, under the same `files:{id}:verify` lock as `VerifyUploadJob`, and only if the file is still pending:
    1. Abort the multipart upload if it's multipart and not completed.
    2. `head()`, then `deleteVersion` or `delete` if the object exists.
    3. Set status `expired` with `failure_reason` `ttl_expired`, and call `ledger->release`.
- Produces: `CleanupStaleUploadsJob`, on queue `maintenance`, which calls the action. It's scheduled `everyFiveMinutes()->withoutOverlapping()->onOneServer()`.

- [ ] **Step 1: Write failing tests**

```php
it('expires pending uploads past expiry plus grace and releases quota', fn () => /* expires_at now-16min → expired, reserved 0 */);
it('leaves uploads inside the grace window alone', fn () => /* expires_at now-10min → still pending */);   // Review Focus #2
it('aborts unfinished multipart uploads', fn () => /* fake aborted contains uploadId */);
it('deletes a late object found at expiry', fn () => /* fake object exists → deletedVersions contains it */);
it('does not touch ready files', fn () => /* ... */);
it('is scheduled every five minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->description ?? '', 'CleanupStaleUploadsJob'));
    expect($event?->expression)->toBe('*/5 * * * *');
});
```

- [ ] **Step 2: Run.** These fail.
- [ ] **Step 3: Implement** as described under Interfaces.
- [ ] **Step 4: Run.** These pass, and the quality gate is green.
- [ ] **Step 5: Commit.** `feat: expire stale uploads on a schedule`

---

### Task 13: Horizon, end-to-end integration suite, README

**Files:**
- Modify: `config/horizon.php`, `app/Providers/HorizonServiceProvider.php`, `docker-compose.yml` (`horizon` service), `README.md`
- Test: `tests/Integration/UploadFlowTest.php` (`group('integration')`)

**Interfaces:**
- Horizon supervisors: `uploads` (min 2, max 10 processes, `balance=auto`), `maintenance` (1–2) and `default` (1–3). The `viewHorizon` gate allows any user in `local`. In other environments it allows emails listed in env `HORIZON_ALLOWED_EMAILS` (comma-separated). Everyone else gets a 403.

- [ ] **Step 1: Write failing end-to-end tests.** These use the real `S3StorageGateway` and LocalStack, run with the queue `sync`, and call `$this->artisan('uploads:consume-s3-events --once')` for the event path.

```php
it('single upload: initiate → PUT → event → ready → download', function () {
    actingAsUser();
    $init = $this->postJson('/api/v1/uploads', ['filename' => 'hi.txt', 'mime_type' => 'text/plain', 'size_bytes' => 5])->json();
    Http::withHeaders($init['upload']['headers'])->withBody('hello', 'text/plain')->put($init['upload']['url'])->throw();
    $this->artisan('uploads:consume-s3-events', ['--once' => true])->assertSuccessful();
    $this->getJson("/api/v1/files/{$init['id']}")->assertJsonPath('status', 'ready');
    $url = $this->getJson("/api/v1/files/{$init['id']}/download")->json('url');
    expect(Http::get($url)->body())->toBe('hello');
});
it('multipart upload with a lowered threshold', function () {
    config(['uploads.multipart_threshold_bytes' => 6 * 1024 * 1024, 'uploads.part_size_bytes' => 5 * 1024 * 1024]);
    // 2 parts → PUT each, collect ETag → /complete with parts → ready, size verified
});
it('rejects a lying client: PUT with a different content-type fails at S3', fn () => /* 403 from S3; file stays pending */);
it('cannot overwrite a ready file with the original URL', fn () => /* second PUT → 412 */);
```

- [ ] **Step 2: Run.** `php artisan test --group=integration` fails until the wiring is complete. Fix any wiring gaps the tests expose: the env, the consumer's SQS client config, and so on.
- [ ] **Step 3: README.** Cover:
  - Local setup: `cp .env.example .env`, `docker compose up -d`, `php artisan migrate`, `php artisan users:create`.
  - Getting a token, then a curl walkthrough of single and multipart uploads, stating that clients must send exactly the returned `headers`.
  - The run commands for the unit/feature and integration test suites.
  - The production AWS checklist copied from §11: bucket, IAM statements, notification, DLQ and lifecycle rules.
- [ ] **Step 4: Run** the whole suite: `php artisan test` (including integration), plus Pint and PHPStan. Everything is green.
- [ ] **Step 5: Commit.** `feat: horizon supervisors, end-to-end integration tests and README`
