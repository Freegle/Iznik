<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_modnotifs_table.php
 * @property int $id
 * @property int $userid
 * @property \Illuminate\Support\Carbon $timestamp
 * @property string $data
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModNotif newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModNotif newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModNotif query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModNotif whereData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModNotif whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModNotif whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModNotif whereUserid($value)
 * @mixin \Eloquent
 */
class ModNotif extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'modnotifs';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
