<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Files\Contracts\FileRepository;
use App\Domain\Files\Enums\FileStatus;
use App\Models\File;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

final class EloquentFileRepository implements FileRepository
{
    public function findByObjectKey(string $key): ?File
    {
        // Soft-deleted rows included: S3 events for deleted files must still be resolvable.
        return File::query()->withTrashed()->where('object_key', $key)->first();
    }

    public function stalePending(CarbonImmutable $cutoff, int $limit): Collection
    {
        return File::query()
            ->where('status', FileStatus::Pending)
            ->where('expires_at', '<', $cutoff)
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();
    }

    public function paginateForUser(int $userId, ?FileStatus $status, int $perPage): CursorPaginator
    {
        return File::query()
            ->where('user_id', $userId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
