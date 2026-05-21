<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_trysts_table.php
 * @property int $id
 * @property \Illuminate\Support\Carbon $arrangedat
 * @property \Illuminate\Support\Carbon|null $arrangedfor
 * @property int $user1
 * @property int $user2
 * @property bool $icssent
 * @property string|null $ics1uid
 * @property string|null $ics2uid
 * @property string|null $user1response
 * @property string|null $user2response
 * @property \Illuminate\Support\Carbon|null $remindersent
 * @property \Illuminate\Support\Carbon|null $user1confirmed
 * @property \Illuminate\Support\Carbon|null $user2confirmed
 * @property \Illuminate\Support\Carbon|null $user1declined
 * @property \Illuminate\Support\Carbon|null $user2declined
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereArrangedat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereArrangedfor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereIcs1uid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereIcs2uid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereIcssent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereRemindersent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereUser1($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereUser1confirmed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereUser1declined($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereUser1response($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereUser2($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereUser2confirmed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereUser2declined($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tryst whereUser2response($value)
 * @mixin \Eloquent
 */
class Tryst extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'trysts';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'arrangedat' => 'datetime',
        'arrangedfor' => 'datetime',
        'icssent' => 'boolean',
        'remindersent' => 'datetime',
        'user1confirmed' => 'datetime',
        'user2confirmed' => 'datetime',
        'user1declined' => 'datetime',
        'user2declined' => 'datetime',
    ];
}
