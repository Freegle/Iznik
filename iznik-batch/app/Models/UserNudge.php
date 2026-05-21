<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_nudges_table.php
 * @property int $id
 * @property int $fromuser
 * @property int $touser
 * @property \Illuminate\Support\Carbon $timestamp
 * @property \Illuminate\Support\Carbon|null $responded
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNudge newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNudge newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNudge query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNudge whereFromuser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNudge whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNudge whereResponded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNudge whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNudge whereTouser($value)
 * @mixin \Eloquent
 */
class UserNudge extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_nudges';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
        'responded' => 'datetime',
    ];
}
