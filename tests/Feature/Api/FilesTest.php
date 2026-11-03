<?php

use App\Domain\Files\Enums\FileStatus;
use App\Domain\Files\Events\FileDeleted;
use App\Domain\Files\Services\QuotaLedger;
use App\Jobs\DeleteObjectJob;
use App\Models\File;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('lists only own files, newest first, filterable by status', function () {
    $user = actingAsUser();
    $old = File::factory()->ready()->create(['user_id' => $user->id, 'created_at' => now()->subHour()]);
    $new = File::factory()->ready()->create(['user_id' => $user->id, 'created_at' => now()]);
    $pending = File::factory()->pending()->create(['user_id' => $user->id, 'created_at' => now()->subMinute()]);
    File::factory()->ready()->create(['user_id' => User::factory()->create()->id]);
    File::factory()->ready()->create(['user_id' => $user->id])->delete();

    $ids = $this->getJson('/api/v1/files')->assertOk()->json('data.*.id');
    expect($ids)->toBe([$new->id, $pending->id, $old->id]);

    $this->getJson('/api/v1/files?status=pending')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id);
    $this->getJson('/api/v1/files?status=deleted')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('paginates with a cursor', function () {
    $user = actingAsUser();
    File::factory()->ready()->count(3)->create(['user_id' => $user->id]);

    $first = $this->getJson('/api/v1/files?per_page=2')->assertOk()->assertJsonCount(2, 'data');
    $next = $first->json('meta.next_cursor');
    expect($next)->not->toBeNull();
    $this->getJson('/api/v1/files?per_page=2&cursor='.$next)->assertOk()->assertJsonCount(1, 'data');
});

it('caps per_page at 100', function () {
    actingAsUser();

    $this->getJson('/api/v1/files?per_page=500')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $this->getJson('/api/v1/files?per_page=100')->assertOk();
});

it('shows own file and 404s for others', function () {
    $user = actingAsUser();
    $mine = File::factory()->ready()->create(['user_id' => $user->id]);
    $other = File::factory()->ready()->create();

    $this->getJson("/api/v1/files/{$mine->id}")->assertOk()->assertJsonPath('id', $mine->id);
    $this->getJson("/api/v1/files/{$other->id}")->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
});

it('404s for soft-deleted files', function () {
    $user = actingAsUser();
    $file = File::factory()->ready()->create(['user_id' => $user->id]);
    $file->delete();

    $this->getJson("/api/v1/files/{$file->id}")->assertStatus(404);
});

it('returns a download url for ready files only', function () {
    $user = actingAsUser();
    $ready = File::factory()->ready()->create(['user_id' => $user->id]);
    $pending = File::factory()->pending()->create(['user_id' => $user->id]);

    $response = $this->getJson("/api/v1/files/{$ready->id}/download")->assertOk()->assertJsonStructure(['url', 'expires_at']);
    expect(abs(Carbon::parse($response->json('expires_at'))->diffInSeconds(now()->addSeconds(300), false)))->toBeLessThan(5);

    $this->getJson("/api/v1/files/{$pending->id}/download")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE');
    $this->getJson('/api/v1/files/'.File::factory()->ready()->create()->id.'/download')->assertStatus(404);
});

it('deletes a ready file: soft delete, quota released, object delete queued', function () {
    Queue::fake();
    Event::fake([FileDeleted::class]);
    $user = actingAsUser();
    $file = File::factory()->ready()->create(['user_id' => $user->id, 'size_bytes' => 5]);
    app(QuotaLedger::class)->reserve($user->id, 5);
    app(QuotaLedger::class)->commit($user->id, 5);
    expect(usageOf($user->id)->usedBytes)->toBe(5);

    $this->deleteJson("/api/v1/files/{$file->id}")->assertNoContent();

    $trashed = File::withTrashed()->findOrFail($file->id);
    expect($trashed->status)->toBe(FileStatus::Deleted)->and($trashed->trashed())->toBeTrue();
    expect(usageOf($user->id)->usedBytes)->toBe(0);
    Queue::assertPushed(DeleteObjectJob::class, fn ($j) => $j->objectKey === $file->object_key && $j->permanent === false);
    Event::assertDispatched(FileDeleted::class, fn ($e) => $e->fileId === $file->id);

    $this->deleteJson("/api/v1/files/{$file->id}")->assertStatus(404);
    expect(usageOf($user->id)->usedBytes)->toBe(0);
});

it('deletes rejected and expired files without quota or S3 changes', function (FileStatus $status) {
    Queue::fake();
    $user = actingAsUser();
    $file = File::factory()->create(['user_id' => $user->id, 'status' => $status]);

    $this->deleteJson("/api/v1/files/{$file->id}")->assertNoContent();

    expect(File::withTrashed()->find($file->id)->status)->toBe(FileStatus::Deleted);
    expect(usageOf($user->id)->usedBytes)->toBe(0);
    Queue::assertNothingPushed();
})->with([FileStatus::Rejected, FileStatus::Expired]);

it('refuses to delete a pending upload', function () {
    [, $file] = ownPendingFile();

    $this->deleteJson("/api/v1/files/{$file->id}")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE');
    expect($file->fresh()->status)->toBe(FileStatus::Pending);
});

it('404s when deleting another user\'s file', function () {
    actingAsUser();
    $other = File::factory()->ready()->create();

    $this->deleteJson("/api/v1/files/{$other->id}")->assertStatus(404);
});
