<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_messages_promises_table.php
 * @property int $id
 * @property int $msgid
 * @property int $userid
 * @property \Illuminate\Support\Carbon $promisedat
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessagePromise newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessagePromise newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessagePromise query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessagePromise whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessagePromise whereMsgid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessagePromise wherePromisedat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessagePromise whereUserid($value)
 * @mixin \Eloquent
 */
class MessagePromise extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'messages_promises';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'promisedat' => 'datetime',
    ];
}
