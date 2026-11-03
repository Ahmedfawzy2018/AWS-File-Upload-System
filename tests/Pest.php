<?php

use App\Domain\Files\Contracts\StorageGateway;
use App\Domain\Files\Rules\UploadRules;
use App\Domain\Files\ValueObjects\MimeType;
use App\Domain\Files\ValueObjects\ObjectKey;
use App\Infrastructure\Storage\S3StorageGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
