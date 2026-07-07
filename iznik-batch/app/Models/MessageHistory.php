<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_messages_history_table.php
 * @property int $id Unique iD
 * @property int|null $msgid id in the messages table
 * @property \Illuminate\Support\Carbon $arrival When this message arrived at our server
 * @property string|null $source Source of incoming message
 * @property string|null $fromip IP we think this message came from
 * @property string|null $fromhost Hostname for fromip if resolvable, or NULL
 * @property int|null $fromuser
 * @property string|null $envelopefrom
 * @property string|null $fromname
 * @property string|null $fromaddr
 * @property string|null $envelopeto
 * @property int|null $groupid Destination group, if identified
 * @property string|null $subject
 * @property string|null $prunedsubject For spam detection
 * @property string|null $messageid
 * @property bool|null $repost
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereArrival($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereEnvelopefrom($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereEnvelopeto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereFromaddr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereFromhost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereFromip($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereFromname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereFromuser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereGroupid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereMessageid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereMsgid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory wherePrunedsubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereRepost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageHistory whereSubject($value)
 * @mixin \Eloquent
 */
class MessageHistory extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'messages_history';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'arrival' => 'datetime',
        'repost' => 'boolean',
    ];
}
