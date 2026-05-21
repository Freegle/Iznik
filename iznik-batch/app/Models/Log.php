<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_logs_table.php
 * @property int $id Unique ID
 * @property \Illuminate\Support\Carbon $timestamp Machine assumed set to GMT
 * @property int|null $byuser User responsible for action, if any
 * @property string|null $type
 * @property string|null $subtype
 * @property int|null $groupid Any group this log is for
 * @property int|null $user Any user that this log is about
 * @property int|null $msgid id in the messages table
 * @property int|null $configid id in the mod_configs table
 * @property int|null $stdmsgid Any stdmsg for this log
 * @property int|null $bulkopid
 * @property string|null $text
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereBulkopid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereByuser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereConfigid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereGroupid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereMsgid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereStdmsgid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereSubtype($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Log whereUser($value)
 * @mixin \Eloquent
 */
class Log extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'logs';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
