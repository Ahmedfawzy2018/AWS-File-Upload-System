<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property int $used_bytes
 * @property int $reserved_bytes
 * @property int $quota_bytes
 */
class UserStorageUsage extends Model
{
    protected $table = 'user_storage_usage';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
