<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_nearby_table.php
 * @property int $userid
 * @property int $msgid
 * @property \Illuminate\Support\Carbon $timestamp
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNearby newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNearby newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNearby query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNearby whereMsgid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNearby whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserNearby whereUserid($value)
 * @mixin \Eloquent
 */
class UserNearby extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_nearby';
    protected $guarded = [];
    public $timestamps = false;
    public $primaryKey = null;
    public $incrementing = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
