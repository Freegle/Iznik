<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class Notification extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_notifications';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected $casts = [
        'timestamp' => 'datetime',
        'seen' => 'boolean',
    ];

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'touser');
    }

    /**
     * Get the from user.
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fromuser');
    }

    /**
     * Scope to unseen notifications.
     */
    public function scopeUnseen(Builder $query): Builder
    {
        return $query->where('seen', false);
    }

    /**
     * Scope to recent notifications.
     */
    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('timestamp', '>=', now()->subHours($hours));
    }

    /**
     * Scope to notifications in a time range.
     */
    public function scopeInTimeRange(Builder $query, string $from, string $to): Builder
    {
        return $query->where('timestamp', '>=', $from)
            ->where('timestamp', '<=', $to);
    }
}
