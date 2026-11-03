<?php

namespace App\Models;

use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Enums\UploadType;
use App\Domain\Files\ValueObjects\ObjectKey;
use Carbon\CarbonImmutable;
use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property int $user_id
 * @property string $original_name
 * @property string $object_key
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $checksum_sha256
 * @property string|null $version_id
 * @property string|null $etag
 * @property FileStatus $status
 * @property UploadType $upload_type
 * @property string|null $multipart_upload_id
 * @property CarbonImmutable|null $multipart_completed_at
 * @property string|null $failure_reason
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $uploaded_at
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FileStatus::class,
            'upload_type' => UploadType::class,
            'size_bytes' => 'integer',
            'multipart_completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'uploaded_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function objectKey(): ObjectKey
    {
        return ObjectKey::fromString($this->object_key);
    }

    /**
     * Validates and applies a status change in memory; the caller saves.
     */
    public function transitionTo(FileStatus $to): void
    {
        $this->status->assertCanTransitionTo($to);
        $this->status = $to;

        match ($to) {
            FileStatus::Uploaded => $this->uploaded_at = CarbonImmutable::now(),
            FileStatus::Ready => $this->ready_at = CarbonImmutable::now(),
            default => null,
        };
    }
}
