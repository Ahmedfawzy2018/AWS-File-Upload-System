# S3 Upload Service

A Laravel 12 (PHP 8.4) API that lets authenticated users upload large files **directly to a private S3 bucket** using presigned URLs. The API never proxies file bytes. It issues signed URLs, tracks each file's lifecycle, enforces per-user quotas, and verifies every upload against S3 before marking it `ready`.

- Single `PUT` for files up to 100 MB, S3 multipart upload above that (max 5 GB).
- Content-Type, Content-Length and `If-None-Match: *` are bound into the signature, so S3 itself rejects a client that lies about type/size and a URL can create the object only once.
- Upload confirmation arrives through S3 `ObjectCreated` events (SQS) and/or `POST /uploads/{id}/complete`. Both paths are idempotent.
- Sanctum token auth, idempotency keys, rate limits, quota ledger, scheduled cleanup of stale uploads, Horizon for queue workers.

## Architecture

```
Client ──HTTPS──▶ Laravel API ──▶ MySQL (files, quota)     Redis (cache, locks, queues)
   │                  │ presigned URLs (signing needs no network call to S3)
   │                  ▼
   └──PUT bytes────▶ S3 (private, versioned) ──ObjectCreated──▶ SQS (+DLQ)
                                                                  │
                                    uploads:consume-s3-events ◀───┘
                                              │
                      Horizon: VerifyUploadJob (queue uploads),
                      DeleteObjectJob / CleanupStaleUploadsJob (queue maintenance)
```

Code layout: `app/Http` (thin controllers, FormRequests, Resources), `app/Domain/Files` (actions, DTOs, value objects, status enum; depends only on the `StorageGateway`/`FileRepository` contracts), `app/Infrastructure` (S3 gateway, SQS consumer). Design spec: `docs/superpowers/specs/2026-11-03-s3-upload-service-design.md`.

Lifecycle: `pending → uploaded → ready`, `pending → expired` (abort/TTL), `pending → rejected` (verification failed), `ready → deleted`.

## Local setup

Requires Docker. LocalStack provides S3 and SQS locally (bucket `uploads-local`, queue `s3-events`, created on startup).

```bash
cp .env.example .env
docker compose up -d                       # app, nginx, mysql, redis, localstack
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan users:create you@example.com --name="You" --password=secret123

# long-running workers (S3 event consumer, scheduler, Horizon) are opt-in:
docker compose --profile workers up -d
```

The API is served by nginx on `http://localhost:8080` (override with `NGINX_PORT=8081 docker compose up -d`). The commands below assume `NGINX_PORT=8081`. Horizon's dashboard is at `/horizon` and is available in `APP_ENV=local` only in v1. In other environments it stays closed: the app has no web/session login, so the `viewHorizon` gate sees no user. `HORIZON_ALLOWED_EMAILS` (comma-separated) takes effect only once a web/session auth guard is added. Liveness: `GET /health`; readiness (DB + Redis): `GET /health/ready`.

`php`, `composer` and `vendor/bin/*` commands run inside the `app` container (`docker compose exec app ...`).

## API

All endpoints are under `/api/v1` and need `Authorization: Bearer <token>` except token issuance. Send `Accept: application/json`.

| Method | Path | Purpose |
|---|---|---|
| POST | `/auth/tokens` | Issue a token (`email`, `password`, optional `device_name`) |
| DELETE | `/auth/tokens/current` | Revoke the current token |
| POST | `/uploads` | Initiate an upload (single or multipart); optional `Idempotency-Key` header |
| POST | `/uploads/{id}/parts` | Re-sign multipart part URLs (`part_numbers`) |
| POST | `/uploads/{id}/complete` | Confirm the upload (`parts` for multipart); returns `202` |
| POST | `/uploads/{id}/abort` | Abort a pending upload |
| GET | `/files` | List own files (cursor pagination) |
| GET | `/files/{id}` | File metadata |
| GET | `/files/{id}/download` | `{url, expires_at}` presigned GET (ready files only) |
| DELETE | `/files/{id}` | Soft delete; object removal runs in a queued job |
| GET | `/me/usage` | `{used_bytes, reserved_bytes, quota_bytes}` |

Errors share one shape: `{"error":{"code":"...","message":"...","details":{}}}`. Codes: `VALIDATION_FAILED`, `UNSUPPORTED_MEDIA_TYPE`, `FILE_TOO_LARGE`, `CHECKSUM_NOT_SUPPORTED`, `INVALID_PARTS`, `IDEMPOTENCY_KEY_REUSED` (422), `QUOTA_EXCEEDED`, `INVALID_STATE` (409), `NOT_FOUND` (404, also for other users' files), `UNAUTHENTICATED` (401), `RATE_LIMITED` (429).

### Limits (all in `config/uploads.php`, env-overridable)

| Rule | Default | Env |
|---|---|---|
| Max file size | 5 GiB | `UPLOADS_MAX_SIZE_BYTES` |
| Multipart threshold (at or below = single PUT) | 100 MiB | `UPLOADS_MULTIPART_THRESHOLD_BYTES` |
| Multipart part size | 16 MiB (S3 minimum is 5 MiB) | `UPLOADS_PART_SIZE_BYTES` |
| Per-user quota | 10 GiB | `UPLOADS_DEFAULT_QUOTA_BYTES` |
| Single PUT / multipart / download URL TTL | 15 min / 60 min / 5 min | `UPLOADS_TTL_SINGLE_PUT`, `UPLOADS_TTL_MULTIPART`, `UPLOADS_TTL_DOWNLOAD` |
| Rate limit: initiate / other | 60 / 300 per min | `UPLOADS_RATE_LIMIT_INITIATE`, `UPLOADS_RATE_LIMIT_DEFAULT` |
| Idempotency window | 24 h | `UPLOADS_IDEMPOTENCY_TTL` |

Allowed MIME types: `image/jpeg`, `image/png`, `image/webp`, `image/gif`, `application/pdf`, `text/plain`, `text/csv`, `application/zip`, `video/mp4`, and Office docx/xlsx/pptx.

## Walkthrough with curl

Presigned URLs point at the S3 endpoint from `AWS_ENDPOINT` (`http://localstack:4566`, a Docker hostname). From your host, add `--resolve localstack:4566:127.0.0.1` to the S3 requests as below (or add `127.0.0.1 localstack` to `/etc/hosts`). With real AWS the URLs are public and no `--resolve` is needed.

### Get a token

```bash
B=http://localhost:8081/api/v1
TOKEN=$(curl -s -X POST $B/auth/tokens -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"you@example.com","password":"secret123","device_name":"cli"}' | jq -r .token)
AUTH=(-H "Authorization: Bearer $TOKEN" -H 'Accept: application/json')
```

### Single upload

**Clients must send exactly the returned `headers`** (Content-Type, Content-Length, If-None-Match, and `x-amz-checksum-sha256` when a checksum was given). They are part of the signature; anything else is rejected by S3 with 403.

```bash
printf 'hello' > hello.txt
SIZE=$(wc -c < hello.txt | tr -d ' ')

# 1. initiate
RESP=$(curl -s -X POST $B/uploads "${AUTH[@]}" -H 'Content-Type: application/json' \
  -d "{\"filename\":\"hello.txt\",\"mime_type\":\"text/plain\",\"size_bytes\":$SIZE}")
ID=$(echo "$RESP" | jq -r .id)
URL=$(echo "$RESP" | jq -r .upload.url)
HDRS=(); while IFS= read -r l; do HDRS+=("$l"); done \
  < <(echo "$RESP" | jq -r '.upload.headers | to_entries[] | "-H", "\(.key): \(.value)"')

# 2. PUT the bytes straight to S3 with exactly those headers
curl --resolve localstack:4566:127.0.0.1 -X PUT "${HDRS[@]}" --data-binary @hello.txt "$URL"

# 3. confirm (optional when the S3 event consumer is running) and poll
curl -s -X POST $B/uploads/$ID/complete "${AUTH[@]}"
curl -s $B/files/$ID "${AUTH[@]}" | jq .status          # "ready"

# 4. download
DL=$(curl -s $B/files/$ID/download "${AUTH[@]}" | jq -r .url)
curl -s --resolve localstack:4566:127.0.0.1 "$DL"
```

`/complete` returns `202` immediately with the current status; verification runs on the `uploads` queue (Horizon), so poll `GET /files/{id}` until `ready`. A `/complete` sent before the PUT finished leaves the file `pending`, and the later S3 event still verifies it.

### Multipart upload

Files above the threshold return `{"type":"multipart","part_size":...,"parts":[{"part_number":1,"url":"...","headers":{"Content-Length":"16777216"}}],...}`. **Send each part with exactly its returned `headers`**: `Content-Length` is bound into the part URL's signature (every part is `part_size` bytes except the last, which holds the remainder), so a body of any other length is rejected by S3. `POST /uploads/{id}/parts` returns the same `{part_number, url, headers}` shape. To try it with a small file, add `UPLOADS_MULTIPART_THRESHOLD_BYTES=6291456` and `UPLOADS_PART_SIZE_BYTES=5242880` to `.env` (parts must be at least 5 MiB except the last). Then:

```bash
head -c 12582912 /dev/zero | tr '\0' 'x' > big.txt          # 12 MiB = 3 parts
SIZE=$(wc -c < big.txt | tr -d ' ')
RESP=$(curl -s -X POST $B/uploads "${AUTH[@]}" -H 'Content-Type: application/json' \
  -d "{\"filename\":\"big.txt\",\"mime_type\":\"text/plain\",\"size_bytes\":$SIZE}")
ID=$(echo "$RESP" | jq -r .id); PS=$(echo "$RESP" | jq -r .upload.part_size)

mkdir -p parts && split -b $PS big.txt parts/p_
PARTS='[]'; n=1
for f in parts/p_*; do
  URL=$(echo "$RESP" | jq -r ".upload.parts[$((n-1))].url")
  LEN=$(echo "$RESP" | jq -r ".upload.parts[$((n-1))].headers[\"Content-Length\"]")   # must equal the size of $f
  ETAG=$(curl -s -D - -o /dev/null --resolve localstack:4566:127.0.0.1 -X PUT -H "Content-Length: $LEN" --data-binary @$f "$URL" \
    | awk -F': ' 'tolower($1)=="etag"{print $2}' | tr -d '\r')
  PARTS=$(echo "$PARTS" | jq -c --argjson n $n --arg e "$ETAG" '. + [{part_number:$n,etag:$e}]'); n=$((n+1))
done

curl -s -X POST $B/uploads/$ID/complete "${AUTH[@]}" -H 'Content-Type: application/json' -d "{\"parts\":$PARTS}"
curl -s $B/files/$ID "${AUTH[@]}" | jq .status              # "ready" once verified
```

Missing, duplicate, out-of-range or wrong-ETag parts return `422 INVALID_PARTS` and the file stays `pending`, so you can retry with a corrected list. Need fresh part URLs? `POST /uploads/{id}/parts` with `{"part_numbers":[1,2]}`.

## Tests

```bash
docker compose exec app php artisan test --exclude-group=integration   # unit + feature, fake storage gateway
docker compose exec app php artisan test --group=integration           # real S3StorageGateway against LocalStack
docker compose exec app php artisan test                               # everything
docker compose exec app vendor/bin/pint --test                         # code style
docker compose exec app vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Integration tests need `docker compose up -d` (LocalStack with the `uploads-local` bucket and `s3-events` queue). **Stop the `workers` profile first** (`docker compose --profile workers stop s3-consumer`): a running consumer competes with the tests for SQS messages. The end-to-end suite (`tests/Integration/UploadFlowTest.php`) covers single upload through download, multipart, a wrong-Content-Type PUT being rejected by S3, and a second PUT to a ready file's URL failing with 412.

## Production AWS checklist

- **Bucket**: private, Block Public Access on, SSE-S3 default encryption (SSE-KMS optional), versioning on, CORS allowing `PUT` from your web origins and exposing the `ETag` header (browsers need it for multipart).
- **IAM (app task role)**:
  - `s3:PutObject`, `s3:GetObject`, `s3:GetObjectVersion`, `s3:DeleteObject`, `s3:DeleteObjectVersion`, `s3:AbortMultipartUpload`, `s3:ListMultipartUploadParts` on `arn:aws:s3:::<bucket>/users/*` only.
  - `s3:ListBucket` on `arn:aws:s3:::<bucket>` with condition `StringLike: {"s3:prefix": "users/*"}`, so a HEAD on a missing object returns 404 instead of 403.
  - `sqs:ReceiveMessage`, `sqs:DeleteMessage` on the events queue.
- **Notifications**: S3 event `s3:ObjectCreated:*` with key prefix `users/` to an SQS queue. Attach a dead-letter queue with `maxReceiveCount` 5, and allow `s3.amazonaws.com` to `sqs:SendMessage` on the queue. Set `S3_EVENTS_QUEUE_URL`.
- **Lifecycle rules**: abort incomplete multipart uploads after 2 days; expire noncurrent versions after 7 days (this is the recovery window for user deletes); remove expired delete markers.
- **No static keys**: use the ECS/EKS task role. Leave `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` and `AWS_ENDPOINT` unset; `AWS_USE_PATH_STYLE_ENDPOINT=false`; set `AWS_BUCKET` and `AWS_DEFAULT_REGION`. Inject secrets from Secrets Manager.
- **Processes**: run N stateless API containers, `php artisan horizon` (scale separately), one `uploads:consume-s3-events` consumer (or more), and `php artisan schedule:work`/cron `schedule:run` (cleanup runs every 5 minutes). The Horizon dashboard is closed outside `local` (no web auth guard in v1; `HORIZON_ALLOWED_EMAILS` only matters once one exists). Monitor with `php artisan horizon:status`, `php artisan horizon:supervisors` and the JSON logs.

## Known limitations / verify on real AWS

- Enforcement of the signed `Content-Length` and `If-None-Match: *` headers (403 on a mismatched Content-Type, 412 on overwrite) was verified against **LocalStack only**. Confirm both on a real bucket before relying on them, including conditional writes on multipart completion.
- Registration, SSO, admin roles, content sniffing / virus scanning, and a restore endpoint are out of scope for v1.
- Versioning is a safety net for accidental deletes, not a user-facing feature.
