<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_aboutme_table.php
 * @property int $id
 * @property int $userid
 * @property \Illuminate\Support\Carbon $timestamp
 * @property string|null $text
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAboutMe newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAboutMe newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAboutMe query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAboutMe whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAboutMe whereText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAboutMe whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserAboutMe whereUserid($value)
 * @mixin \Eloquent
 */
class UserAboutMe extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_aboutme';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
