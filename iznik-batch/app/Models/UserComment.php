<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_comments_table.php
 * @property int $id
 * @property int $userid
 * @property int|null $groupid
 * @property int|null $byuserid
 * @property \Illuminate\Support\Carbon $date
 * @property \Illuminate\Support\Carbon $reviewed
 * @property string|null $user1
 * @property string|null $user2
 * @property string|null $user3
 * @property string|null $user4
 * @property string|null $user5
 * @property string|null $user6
 * @property string|null $user7
 * @property string|null $user8
 * @property string|null $user9
 * @property string|null $user10
 * @property string|null $user11
 * @property bool $flag
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereByuserid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereFlag($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereGroupid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereReviewed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUser1($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUser10($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUser11($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUser2($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUser3($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUser4($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUser5($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUser6($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUser7($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUser8($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUser9($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserComment whereUserid($value)
 * @mixin \Eloquent
 */
class UserComment extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_comments';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'reviewed' => 'datetime',
        'flag' => 'boolean',
    ];
}
