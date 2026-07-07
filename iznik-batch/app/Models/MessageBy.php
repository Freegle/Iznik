<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_messages_by_table.php
 * @property int $id
 * @property int $msgid
 * @property int|null $userid
 * @property \Illuminate\Support\Carbon $timestamp
 * @property int $count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageBy newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageBy newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageBy query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageBy whereCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageBy whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageBy whereMsgid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageBy whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageBy whereUserid($value)
 * @mixin \Eloquent
 */
class MessageBy extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'messages_by';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
