<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_messages_reneged_table.php
 * @property int $id
 * @property \Illuminate\Support\Carbon $timestamp
 * @property int $msgid
 * @property int|null $userid
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageReneged newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageReneged newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageReneged query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageReneged whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageReneged whereMsgid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageReneged whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageReneged whereUserid($value)
 * @mixin \Eloquent
 */
class MessageReneged extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'messages_reneged';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
