<?php

declare(strict_types=1);

namespace App\Domain\Files\Contracts;

use App\Domain\Files\Enums\FileStatus;
use App\Models\File;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

interface FileRepository
{
    public function findByObjectKey(string $key): ?File;

    /**
     * Pending files whose expires_at is before the cutoff.
     *
     * @return Collection<int, File>
     */
    public function stalePending(CarbonImmutable $cutoff, int $limit): Collection;

    /**
     * Newest first, soft-deleted excluded.
     *
     * @return CursorPaginator<int, File>
     */
    public function paginateForUser(int $userId, ?FileStatus $status, int $perPage): CursorPaginator;
}
