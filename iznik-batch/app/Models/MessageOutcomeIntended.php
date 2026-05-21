<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_messages_outcomes_intended_table.php
 * @property int $id
 * @property \Illuminate\Support\Carbon $timestamp
 * @property int $msgid
 * @property string $outcome
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageOutcomeIntended newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageOutcomeIntended newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageOutcomeIntended query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageOutcomeIntended whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageOutcomeIntended whereMsgid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageOutcomeIntended whereOutcome($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MessageOutcomeIntended whereTimestamp($value)
 * @mixin \Eloquent
 */
class MessageOutcomeIntended extends Model
{
    protected $table = 'messages_outcomes_intended';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}
