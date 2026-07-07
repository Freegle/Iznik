<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_bounces_emails_table.php
 * @property int $id
 * @property int $emailid
 * @property \Illuminate\Support\Carbon $date
 * @property string|null $reason
 * @property bool $permanent
 * @property bool $reset If we have reset bounces for this email
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BounceEmail newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BounceEmail newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BounceEmail query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BounceEmail whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BounceEmail whereEmailid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BounceEmail whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BounceEmail wherePermanent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BounceEmail whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BounceEmail whereReset($value)
 * @mixin \Eloquent
 */
class BounceEmail extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'bounces_emails';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'permanent' => 'boolean',
        'reset' => 'boolean',
    ];
}
