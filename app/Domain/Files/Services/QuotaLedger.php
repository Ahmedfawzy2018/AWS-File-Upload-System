<?php

declare(strict_types=1);

namespace App\Domain\Files\Services;

use App\Domain\Files\DTOs\StorageUsage;
use App\Domain\Files\Exceptions\QuotaExceeded;
use Illuminate\Support\Facades\DB;

/**
 * Every method is a single atomic statement (reserve: insert-if-missing plus one
 * conditional UPDATE), so it is safe inside or outside a caller's transaction.
 */
final class QuotaLedger
{
    private const TABLE = 'user_storage_usage';

    public function __construct(private readonly int $defaultQuotaBytes) {}

    public function reserve(int $userId, int $bytes): void
    {
        $this->assertPositive($bytes);

        DB::table(self::TABLE)->insertOrIgnore([
            'user_id' => $userId,
            'used_bytes' => 0,
            'reserved_bytes' => 0,
            'quota_bytes' => $this->defaultQuotaBytes,
            'updated_at' => now(),
        ]);

        $affected = DB::update(
            'UPDATE user_storage_usage SET reserved_bytes = reserved_bytes + ?, updated_at = ? '
            .'WHERE user_id = ? AND used_bytes + reserved_bytes + ? <= quota_bytes',
            [$bytes, now(), $userId, $bytes],
        );

        if ($affected === 0) {
            $usage = $this->usage($userId);

            throw new QuotaExceeded(max($usage->quotaBytes - $usage->usedBytes - $usage->reservedBytes, 0));
        }
    }

    public function commit(int $userId, int $bytes): void
    {
        $this->assertPositive($bytes);

        DB::update(
            'UPDATE user_storage_usage SET reserved_bytes = GREATEST(CAST(reserved_bytes AS SIGNED) - ?, 0), '
            .'used_bytes = used_bytes + ?, updated_at = ? WHERE user_id = ?',
            [$bytes, $bytes, now(), $userId],
        );
    }

    public function release(int $userId, int $bytes): void
    {
        $this->assertPositive($bytes);

        DB::update(
            'UPDATE user_storage_usage SET reserved_bytes = GREATEST(CAST(reserved_bytes AS SIGNED) - ?, 0), updated_at = ? WHERE user_id = ?',
            [$bytes, now(), $userId],
        );
    }

    public function releaseUsed(int $userId, int $bytes): void
    {
        $this->assertPositive($bytes);

        DB::update(
            'UPDATE user_storage_usage SET used_bytes = GREATEST(CAST(used_bytes AS SIGNED) - ?, 0), updated_at = ? WHERE user_id = ?',
            [$bytes, now(), $userId],
        );
    }

    public function usage(int $userId): StorageUsage
    {
        $row = DB::table(self::TABLE)->where('user_id', $userId)->first();

        if ($row === null) {
            return new StorageUsage(0, 0, $this->defaultQuotaBytes);
        }

        return new StorageUsage((int) $row->used_bytes, (int) $row->reserved_bytes, (int) $row->quota_bytes);
    }

    private function assertPositive(int $bytes): void
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('Bytes must be at least 1, got '.$bytes.'.');
        }
    }
}
