<?php

use App\Domain\Files\Actions\VerifyUpload;
use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\DTOs\StorageUsage;
use App\Domain\Files\Enums\UploadType;
use App\Domain\Files\Rules\UploadRules;
use App\Domain\Files\Services\QuotaLedger;
use App\Domain\Files\ValueObjects\MimeType;
use App\Domain\Files\ValueObjects\ObjectKey;
use App\Infrastructure\Storage\S3StorageGateway;
use App\Jobs\VerifyUploadJob;
use App\Models\File;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeStorageGateway;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        app()->singleton(StorageGateway::class, FakeStorageGateway::class);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

pest()->extend(TestCase::class)->in('Unit/Domain', 'Unit/Support', 'Integration');

function rules(): UploadRules
{
    return new UploadRules(
        config('uploads.allowed_mime_types'),
        config('uploads.max_size_bytes'),
        config('uploads.multipart_threshold_bytes'),
    );
}

function gateway(): StorageGateway
{
    return app(S3StorageGateway::class);
}

function uniqueKey(): ObjectKey
{
    return ObjectKey::generate('users/test', 1, (string) Str::ulid(), MimeType::fromString('text/plain'), CarbonImmutable::now());
}

function actingAsUser(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    return $user;
}

/** @return array{filename: string, mime_type: string, size_bytes: int} */
function payload(int $size = 1024, string $mime = 'image/png'): array
{
    return ['filename' => 'a.png', 'mime_type' => $mime, 'size_bytes' => $size];
}

function fakeS3(): FakeStorageGateway
{
    $s3 = app(StorageGateway::class);
    assert($s3 instanceof FakeStorageGateway);

    return $s3;
}

function pendingFile(int $size = 5, string $mime = 'text/plain', ?User $user = null): File
{
    $user ??= User::factory()->create();
    $file = File::factory()->pending()->create([
        'user_id' => $user->id,
        'size_bytes' => $size,
        'mime_type' => $mime,
    ]);
    app(QuotaLedger::class)->reserve($user->id, $size);

    return $file;
}

/** @return array{User, File} */
function ownPendingFile(): array
{
    $user = actingAsUser();

    return [$user, pendingFile(5, 'text/plain', $user)];
}

/** Pending multipart file with a real fake upload id and $parts parts. */
function ownMultipartFile(User $user, int $parts = 3): File
{
    $partSize = (int) config('uploads.part_size_bytes');
    $size = ($parts - 1) * $partSize + 1;
    $file = File::factory()->pending()->create([
        'user_id' => $user->id,
        'size_bytes' => $size,
        'mime_type' => 'text/plain',
        'upload_type' => UploadType::Multipart,
    ]);
    $file->multipart_upload_id = fakeS3()->createMultipart($file->objectKey(), MimeType::fromString('text/plain'));
    $file->save();
    app(QuotaLedger::class)->reserve($user->id, $size);

    return $file;
}

function runVerify(File $file): void
{
    (new VerifyUploadJob($file->id))->handle(app(VerifyUpload::class));
}

function usageOf(int $userId): StorageUsage
{
    return app(QuotaLedger::class)->usage($userId);
}

function fixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
}
