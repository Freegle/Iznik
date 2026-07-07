<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_banned_table.php
 * @property int $userid
 * @property int $groupid
 * @property \Illuminate\Support\Carbon $date
 * @property int|null $byuser
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBanned newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBanned newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBanned query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBanned whereByuser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBanned whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBanned whereGroupid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserBanned whereUserid($value)
 * @mixin \Eloquent
 */
class UserBanned extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_banned';
    protected $guarded = [];
    public $timestamps = false;
    public $primaryKey = null;
    public $incrementing = false;

    protected $casts = [
        'date' => 'datetime',
    ];
}
