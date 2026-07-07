<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_replytime_table.php
 * @property int $id
 * @property int $userid
 * @property int|null $replytime
 * @property \Illuminate\Support\Carbon $timestamp
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserReplyTime newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserReplyTime newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserReplyTime query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserReplyTime whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserReplyTime whereReplytime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserReplyTime whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserReplyTime whereUserid($value)
 * @mixin \Eloquent
 */
class UserReplyTime extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_replytime';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
