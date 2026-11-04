<?php

namespace Database\Factories;

use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Enums\UploadType;
use App\Domain\Files\ValueObjects\MimeType;
use App\Domain\Files\ValueObjects\ObjectKey;
use App\Models\File;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    protected $model = File::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = strtolower((string) Str::ulid());
        $userId = User::factory();

        return [
            'id' => $id,
            'user_id' => $userId,
            'original_name' => 'file.txt',
            'object_key' => fn (array $attrs) => ObjectKey::generate(
                (string) config('uploads.key_prefix', 'users'),
                (int) $attrs['user_id'],
                $id,
                MimeType::fromString('text/plain'),
                CarbonImmutable::now(),
            )->value(),
            'mime_type' => 'text/plain',
            'size_bytes' => 5,
            'status' => FileStatus::Pending,
            'upload_type' => UploadType::Single,
            'expires_at' => CarbonImmutable::now()->addMinutes(15),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => FileStatus::Pending,
            'expires_at' => CarbonImmutable::now()->addMinutes(15),
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => FileStatus::Ready,
            'uploaded_at' => CarbonImmutable::now(),
            'ready_at' => CarbonImmutable::now(),
            'etag' => 'etag',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => FileStatus::Pending,
            'expires_at' => CarbonImmutable::now()->subHour(),
        ]);
    }
}
