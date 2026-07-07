<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_sessions_table.php
 * @property int $id
 * @property int|null $userid
 * @property int $series
 * @property string $token
 * @property \Illuminate\Support\Carbon $date
 * @property \Illuminate\Support\Carbon $lastactive
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereLastactive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereSeries($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereUserid($value)
 * @mixin \Eloquent
 */
class UserSession extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'sessions';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'lastactive' => 'datetime',
    ];
}
