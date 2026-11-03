<?php

declare(strict_types=1);

use App\Domain\Files\ValueObjects\FileSize;
use App\Domain\Files\ValueObjects\MimeType;
use App\Jobs\VerifyUploadJob;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('integration');

it('consumes a real ObjectCreated event for a pending file', function () {
    $file = File::factory()->pending()->create([
        'user_id' => User::factory()->create()->id,
        'size_bytes' => 5,
        'mime_type' => 'text/plain',
        'object_key' => 'users/test/'.date('Y/m').'/'.Str::ulid().'.txt',
    ]);
    $req = gateway()->presignPut($file->objectKey(), MimeType::fromString('text/plain'), FileSize::fromBytes(5), 900);
    Http::withHeaders($req->headers)->withBody('hello', 'text/plain')->put($req->url)->throw();

    Queue::fake();
    for ($i = 0; $i < 10; $i++) {
        $this->artisan('uploads:consume-s3-events', ['--once' => true])->assertExitCode(0);
        if (Queue::pushed(VerifyUploadJob::class)->isNotEmpty()) {
            break;
        }
    }

    Queue::assertPushed(VerifyUploadJob::class, fn ($j) => $j->fileId === $file->id);
});
