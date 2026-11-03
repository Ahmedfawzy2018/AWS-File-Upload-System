# S3 File Upload Service — Design

**Date:** 2026-11-03
**Status:** Draft, awaiting approval
**Repo:** `AWS-File-Upload-System`

> Decisions marked **(default)** were proposed by Claude and accepted wholesale by the owner ("go with your defaults"). Decisions marked **(builder's call)** are minor and were settled during the builder review. Anything not marked came directly from the owner.

---

## 1. Intent

Build a Laravel service that lets clients upload files to Amazon S3 **using presigned URLs**, so file bytes go straight from the client to S3 and never pass through the application servers. The service has its own responsibilities: **authentication, queueing, and business logic** (what may be uploaded, by whom, how much, and what happens after upload).

The owner requires the solution to be **scalable** and **maintainable**.

### Why presigned URLs
- App servers don't handle file bytes, so the API stays small, fast and cheap to scale.
- S3 absorbs the upload bandwidth and concurrency.
- The server keeps full control: it decides the object key, size limit, content type and expiry of each URL.

## 2. Goals

1. Authenticated clients can request an upload, receive a presigned PUT URL (or multipart URLs for large files), upload directly to S3, and have the file confirmed and recorded.
2. Authenticated clients can list their files, fetch metadata, get a short-lived download URL, and delete files.
3. Business rules are enforced server-side **before** a URL is signed: allowed MIME types, max file size, per-user storage quota, rate limits.
4. Post-upload work runs asynchronously on queues and is easy to extend (new job = new listener, no changes to the upload flow).
5. The API tier is stateless and horizontally scalable; workers scale independently of the API.
6. The code is layered (HTTP → Auth → Domain → Infrastructure) with S3 behind an interface, so the domain is testable without AWS.

## 3. Non-goals (v1)

- Virus scanning, thumbnails, image/video processing. The pipeline must have a clear extension point for them, but none ship in v1.
- A web UI. v1 is API only.
- Multi-region replication or cross-account buckets.
- Public (unauthenticated) file hosting.
- Resumable uploads across sessions beyond what S3 multipart gives within one upload's TTL.

## 4. Anti-goals (failure even if it "works")

- File bytes streamed through Laravel (defeats the architecture).
- Clients choosing the S3 object key or bucket (path traversal / overwrite risk).
- A file marked `ready` that doesn't exist in S3 or doesn't match the declared size/type.
- A public bucket, or long-lived/unscoped presigned URLs.
- Business logic in controllers or S3 SDK calls scattered across the codebase.
- Orphaned objects or `pending` rows accumulating forever.

## 5. Tech stack (default)

| Concern | Choice |
|---|---|
| Language / framework | PHP 8.4, Laravel 12 |
| Auth | Laravel Sanctum (personal access / API tokens) |
| Database | MySQL 8 (Amazon RDS in prod) |
| Cache, rate limiting, locks, queue backend | Redis (ElastiCache in prod) |
| Queue runner | Laravel Horizon |
| S3 events | S3 → SQS `ObjectCreated` notifications, consumed by a worker |
| Storage SDK | AWS SDK for PHP v3 (via a thin adapter; not Laravel's `Storage` facade for presigning multipart) |
| Downloads | Presigned S3 GET URLs in v1; CloudFront signed URLs as a later swap behind the same interface |
| Tests | Pest; feature tests with a fake `StorageGateway`; one integration suite against LocalStack/MinIO |
| Local dev | Docker Compose: app (php-fpm + nginx), mysql, redis, horizon worker, localstack (S3 + SQS) |
| Deploy target | Stateless containers (ECS or K8s) behind an ALB; IaC is out of scope for v1 code but required resources are listed in §11 |

## 6. Architecture

```
Clients ──HTTPS──▶ ALB ──▶ Laravel API (N stateless containers)
                              │  HTTP: routes /api/v1, middleware (auth:sanctum, throttle), FormRequests, Resources
                              │  Auth: Sanctum tokens, UploadPolicy / FilePolicy
                              │  Domain: Actions, DTOs, rules, domain events
                              │  Infra: StorageGateway → S3Adapter, Eloquent repositories
                              ├──▶ MySQL (files, upload_parts, quotas)
                              ├──▶ Redis (cache, rate limit, locks, queues)
                              └──▶ signs URLs (no network I/O to S3 for signing)

Clients ──PUT bytes──▶ S3 (private bucket) ──ObjectCreated──▶ SQS ──▶ worker ──▶ VerifyUploadJob
Horizon workers (N, scaled separately): VerifyUploadJob, CleanupStaleUploadsJob, listeners for FileReady/FileDeleted
```

### 6.1 Layers and their rules

- **HTTP** (`app/Http`): controllers are thin: validate (FormRequest) → build DTO → call one Action → return Resource. No business rules here.
- **Auth** (`app/Policies`, Sanctum): the service keeps **its own `users` table for now** (owner's decision). There's no SSO or shared user store in v1. Files and quota belong to the user. Keep auth behind Laravel's standard guard so it can be swapped for SSO or service-to-service auth later without touching the domain. Every file endpoint goes through a policy. A user can only see and act on their own files. Admin role is out of scope for v1.
- **Domain** (`app/Domain/Files`): Actions (`InitiateUpload`, `CompleteUpload`, `AbortUpload`, `GetDownloadUrl`, `DeleteFile`), DTOs, value objects (`ObjectKey`, `FileSize`, `MimeType`), the `FileStatus` enum and its allowed transitions, domain events (`FileUploaded`, `FileReady`, `FileRejected`, `FileDeleted`), rule classes (`AllowedMimeTypes`, `MaxFileSize`, `QuotaRule`). Domain code depends only on interfaces.
- **Infrastructure** (`app/Infrastructure`): `S3StorageGateway implements StorageGateway`, Eloquent repositories, the SQS S3-event consumer.

### 6.2 `StorageGateway` interface (contract)

```php
interface StorageGateway
{
    public function presignPut(ObjectKey $key, MimeType $type, FileSize $size, int $ttlSeconds): PresignedRequest;
    public function createMultipart(ObjectKey $key, MimeType $type): string; // returns uploadId
    public function presignPart(ObjectKey $key, string $uploadId, int $partNumber, int $ttlSeconds): PresignedRequest;
    public function completeMultipart(ObjectKey $key, string $uploadId, array $parts): void; // parts: [partNumber => etag]
    public function abortMultipart(ObjectKey $key, string $uploadId): void;
    public function head(ObjectKey $key): ?ObjectMetadata; // null if missing
    public function presignGet(ObjectKey $key, string $downloadName, int $ttlSeconds): PresignedRequest;
    public function delete(ObjectKey $key): void;
}
```

A `FakeStorageGateway` (in-memory) is shipped for tests.

## 7. Data model

### `files`
| column | type | notes |
|---|---|---|
| id | ULID (PK) | exposed publicly as the file id |
| user_id | FK users | owner |
| original_name | varchar(255) | sanitized, for display/download name only |
| object_key | varchar(512), unique | server-generated |
| mime_type | varchar(127) | declared by client, validated against allow-list |
| size_bytes | bigint unsigned | declared size; verified on completion |
| checksum_sha256 | char(64) nullable | optional client-supplied, **single-part only**; enforced via presigned `x-amz-checksum-sha256`. Sending it for a multipart-sized file returns `422 CHECKSUM_NOT_SUPPORTED` **(builder's call)**: S3 multipart checksums are composite per-part values, not a whole-file SHA-256, so they can't be declared up front. Multipart integrity in v1 = S3 per-part ETag check on complete + size verification. |
| version_id | varchar(255) nullable | S3 version id captured at verification; used for permanent deletes |
| etag | varchar(64) nullable | from S3 HEAD |
| status | enum | see §8 |
| upload_type | enum(`single`,`multipart`) | |
| multipart_upload_id | varchar(255) nullable | |
| failure_reason | varchar(255) nullable | |
| expires_at | timestamp | when a `pending` upload becomes stale |
| uploaded_at, ready_at | timestamp nullable | |
| timestamps, soft deletes | | |

Indexes: `(user_id, status, created_at)`, `(status, expires_at)` for cleanup.

### `user_storage_usage`
`user_id` (PK), `used_bytes`, `reserved_bytes`, `quota_bytes`, `updated_at`. Bytes are **reserved** when an upload is initiated, **committed** to used on `ready`, and **released** on fail/abort/expiry/delete. Updates go through atomic SQL increments inside a transaction with a row lock.

## 8. Upload lifecycle

```
pending ──(object verified)──▶ uploaded ──(post-processing listeners done)──▶ ready
   │                              │
   ├──(abort / TTL expiry)──▶ expired
   └──(verification fails)──▶ rejected ◀──(processing fails)── uploaded
ready ──(delete)──▶ deleted (soft delete + S3 delete job)
```

In v1 there are no processing listeners, so `uploaded → ready` happens immediately in the same job. The `uploaded` state exists so scanners/thumbnails can be added later without migrations. Transitions are enforced by the `FileStatus` enum (`canTransitionTo()`); illegal transitions throw.

### 8.1 Single-part flow (≤ 100 MB)
1. `POST /api/v1/uploads` `{ filename, mime_type, size_bytes, checksum_sha256? }`
2. Server validates rules + quota, reserves bytes, creates `files` row (`pending`, `expires_at = now + 15 min`), generates key `users/{user_id}/{yyyy}/{mm}/{ulid}.{ext}`, presigns PUT with `Content-Type`, `Content-Length` and `If-None-Match: *` bound into the signature (and checksum if given). TTL 15 min. `If-None-Match: *` is an S3 conditional write, so a URL can create the object once and can't be reused to overwrite it after verification **(builder's call)**.
3. Response `201`: `{ id, status, upload: { method: "PUT", url, headers, expires_at } }`
4. Client PUTs the bytes to S3.
5. Confirmation arrives by **either** path (both are idempotent, whichever comes first wins):
   - S3 `ObjectCreated` → SQS → consumer resolves file by `object_key` → dispatches `VerifyUploadJob`.
   - Client calls `POST /api/v1/uploads/{id}/complete` → dispatches `VerifyUploadJob` and returns `202` with current status.
6. `VerifyUploadJob` (holds a Redis lock on the file id): `head()` the object; checks it exists, size == declared, content-type == declared, and stores `etag` and `version_id`. Pass → `uploaded` → `ready`, commit quota, fire `FileReady`. Fail → `rejected`, **permanently** delete the object (all versions), release quota, fire `FileRejected`.
7. The S3 event consumer ignores `ObjectCreated` events for files that aren't `pending`. It logs them at warning level as possible tampering, which should be impossible because of conditional writes **(builder's call)**.

### 8.2 Multipart flow (> 100 MB, up to max size)
1. `POST /api/v1/uploads` with the same payload. Server sees size > threshold, calls `createMultipart`, stores `multipart_upload_id`, computes part count (part size 16 MB **(builder's call)**, S3 min 5 MB, max 10,000 parts), and returns presigned URLs for all parts: `{ upload: { type: "multipart", part_size, parts: [{ part_number, url }], expires_at } }`. TTL 1 hour.
2. Client uploads parts in parallel and collects ETags.
3. `POST /api/v1/uploads/{id}/complete` `{ parts: [{ part_number, etag }] }` → server calls `completeMultipart` with `If-None-Match: *`, then dispatches `VerifyUploadJob` (same as single-part).
4. `POST /api/v1/uploads/{id}/abort` → `abortMultipart`, status `expired`, release quota.
5. If a client needs fresh part URLs before expiry: `POST /api/v1/uploads/{id}/parts` `{ part_numbers: [..] }` → re-signed URLs.

### 8.3 Cleanup
- `CleanupStaleUploadsJob` runs every 5 min via the scheduler: finds `pending` rows past `expires_at`, aborts multipart if any, deletes any partial object, marks `expired`, releases reserved quota.
- S3 lifecycle rules: abort incomplete multipart uploads after 2 days; expire noncurrent versions after 7 days; remove expired delete markers.

### 8.4 Versioning and deletes **(builder's call)**
Versioning is a **safety net** against accidental deletes. It isn't a user-facing restore feature, and v1 has no restore endpoint.
- **User delete:** soft-delete the row, then `DeleteObjectJob` does a plain delete, which leaves a delete marker. The bytes become a noncurrent version, and the lifecycle rule purges them after 7 days. That gives ops a 7-day recovery window. Quota is released straight away.
- **Rejected / expired uploads:** permanent delete by `version_id`, so no recovery window and no leftover bytes.

## 9. API (v1)

All endpoints are under `/api/v1` and require `auth:sanctum`, except token issuance.

| Method | Path | Purpose |
|---|---|---|
| POST | `/auth/tokens` | Issue a Sanctum token (email + password). Registration is out of scope; users are seeded or created via an artisan command (`users:create`). |
| DELETE | `/auth/tokens/current` | Revoke current token |
| POST | `/uploads` | Initiate upload (single or multipart) |
| POST | `/uploads/{id}/parts` | Re-sign multipart part URLs |
| POST | `/uploads/{id}/complete` | Confirm upload (body has parts for multipart) |
| POST | `/uploads/{id}/abort` | Abort a pending upload |
| GET | `/files` | List own files (cursor pagination, filter by status) |
| GET | `/files/{id}` | File metadata |
| GET | `/files/{id}/download` | `{ url, expires_at }`, presigned GET, 5 min TTL, `Content-Disposition` with original name. Only for `ready` files. |
| DELETE | `/files/{id}` | Soft delete; S3 delete happens in a queued job; quota released |
| GET | `/me/usage` | `{ used_bytes, reserved_bytes, quota_bytes }` |
| GET | `/health` | Liveness (no auth); `/health/ready` checks DB + Redis |

Errors use one JSON shape: `{ "error": { "code": "QUOTA_EXCEEDED", "message": "...", "details": {} } }`. Codes include `VALIDATION_FAILED` (422), `UNSUPPORTED_MEDIA_TYPE` (422), `FILE_TOO_LARGE` (422), `QUOTA_EXCEEDED` (409), `INVALID_STATE` (409), `NOT_FOUND` (404, also used for files you don't own), `RATE_LIMITED` (429).

`POST /uploads` and `/complete` accept an optional `Idempotency-Key` header. A repeat with the same key and user within 24 h returns the original response (stored in Redis).

## 10. Business rules (defaults, all in `config/uploads.php`, env-overridable)

| Rule | Default |
|---|---|
| Allowed MIME types | `image/jpeg, image/png, image/webp, image/gif, application/pdf, text/plain, text/csv, application/zip, video/mp4`, plus Office docx/xlsx/pptx |
| Max file size | 5 GB |
| Multipart threshold | 100 MB |
| Default per-user quota | 10 GB |
| Single PUT URL TTL | 15 min |
| Multipart URL TTL | 60 min |
| Download URL TTL | 5 min |
| Rate limit, `POST /uploads` | 60/min per user |
| Rate limit, other endpoints | 300/min per user |
| Filename | stripped of path separators/control chars, max 255; never used in the object key |

MIME type is checked against the declared value (bound into the signature, so S3 rejects a mismatched `Content-Type`). Content sniffing / magic-byte checks are a future processing listener, not v1.

## 11. Security and AWS setup

- Bucket: private, Block Public Access on, SSE-S3 by default (SSE-KMS optional via config), versioning on, CORS allowing `PUT` from configured origins and exposing `ETag`.
- IAM: the app role gets `s3:PutObject`, `s3:GetObject`, `s3:GetObjectVersion`, `s3:DeleteObject`, `s3:DeleteObjectVersion`, `s3:AbortMultipartUpload`, `s3:ListMultipartUploadParts` on `arn:aws:s3:::<bucket>/users/*` only. It also gets `s3:ListBucket` on the bucket with the condition `s3:prefix = users/*`, so HEAD on a missing object returns 404 instead of 403. The role also gets plus `sqs:ReceiveMessage`/`DeleteMessage` on the events queue. No static keys in prod (task role); `.env` keys only for local.
- S3 event notification: `s3:ObjectCreated:*` on prefix `users/` → SQS queue (with a DLQ, maxReceiveCount 5).
- Secrets come from env, injected from Secrets Manager in prod.
- Object keys are server-generated; the client filename never reaches S3 paths.
- Authorization: `FilePolicy` checks ownership; non-owners get 404, not 403.

## 12. Scalability

- The API keeps no state: tokens live in the DB with cache, rate limits and locks live in Redis, no local disk. Scale horizontally behind the ALB.
- Presigning is local CPU work with no S3 round trip.
- Workers (Horizon) scale independently. Queues: `uploads` (verify), `maintenance` (cleanup, deletes), `default`.
- Jobs are idempotent and guarded by a Redis lock per file id. Duplicate S3 events and duplicate `/complete` calls are safe.
- DB: indexes per §7; reads can move to a replica later through Laravel's read/write connection config.
- Downloads use presigned GET URLs (CloudFront later), so file traffic never passes through the API.

## 13. Observability

- Structured JSON logs with a request correlation id (`X-Request-Id`, generated if absent) carried into queued jobs.
- Log events: upload initiated / verified / rejected / expired, with file id, user id and size. Never log presigned URLs (they're bearer credentials).
- `/health` and `/health/ready` endpoints. Horizon dashboard protected behind an auth gate.

## 14. Project structure

```
app/
  Domain/Files/
    Actions/  DTOs/  Enums/FileStatus.php  Events/  Exceptions/  Rules/  ValueObjects/
    Contracts/StorageGateway.php  Contracts/FileRepository.php
  Infrastructure/
    Storage/S3StorageGateway.php
    Persistence/EloquentFileRepository.php
    Queue/S3EventConsumer.php            (artisan command: uploads:consume-s3-events, long-polls SQS)
  Http/Controllers/Api/V1/  Http/Requests/  Http/Resources/  Http/Middleware/
  Jobs/VerifyUploadJob.php  Jobs/CleanupStaleUploadsJob.php  Jobs/DeleteObjectJob.php
  Policies/FilePolicy.php
  Models/File.php  Models/UserStorageUsage.php
config/uploads.php
tests/Unit/Domain  tests/Feature/Api  tests/Integration (LocalStack)
docker/  docker-compose.yml
```

Bindings (`StorageGateway → S3StorageGateway`, etc.) live in a `FilesServiceProvider`.

## 15. Testing strategy

- **Unit:** value objects, `FileStatus` transitions, rules, quota math, Actions with fake gateway and in-memory repo.
- **Feature (HTTP):** every endpoint, including auth, policy (404 for others' files), validation errors, quota exceeded, idempotency, state conflicts. Uses `FakeStorageGateway`.
- **Job tests:** `VerifyUploadJob` pass/fail paths, duplicate dispatch, `CleanupStaleUploadsJob`.
- **Integration (opt-in group):** against LocalStack: real presign → HTTP PUT → HEAD → complete, single and multipart.
- Static analysis: PHPStan (Larastan) level 8; Laravel Pint for style. CI is a GitHub Actions workflow running Pint, PHPStan and Pest **(builder's call)**.

## 16. Left to the builder

- Exact class and method names beyond the contracts above.
- Migration details, factory and seeder contents.
- Docker image specifics.
- How the SQS consumer is packaged (artisan long-poll command run under Supervisor/as its own container is the expected shape).
- Order of implementation, as long as each slice is tested.

## 17. Open items for later versions

- Virus scanning (e.g. ClamAV or GuardDuty Malware Protection for S3) as a listener on `FileUploaded` that gates `uploaded → ready`.
- Thumbnails / image variants.
- CloudFront signed downloads.
- Multi-tenant / service-to-service auth (Passport client credentials) if other systems need to call the service.
- Admin role and quota management endpoints.
