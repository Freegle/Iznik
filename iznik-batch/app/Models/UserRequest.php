<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_requests_table.php
 * @property int $id
 * @property int $userid
 * @property string $type
 * @property \Illuminate\Support\Carbon $date
 * @property \Illuminate\Support\Carbon|null $completed
 * @property int|null $completedby
 * @property int|null $addressid
 * @property string|null $to
 * @property \Illuminate\Support\Carbon|null $notifiedmods
 * @property bool $paid
 * @property int|null $amount
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest whereAddressid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest whereCompleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest whereCompletedby($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest whereNotifiedmods($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest wherePaid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest whereTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserRequest whereUserid($value)
 * @mixin \Eloquent
 */
class UserRequest extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_requests';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'completed' => 'datetime',
        'notifiedmods' => 'datetime',
        'paid' => 'boolean',
    ];
}
