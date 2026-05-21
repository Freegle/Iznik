<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_spam_users_table.php
 * @property int $id
 * @property int $userid
 * @property int|null $byuserid
 * @property \Illuminate\Support\Carbon $added
 * @property int|null $addedby
 * @property string $collection
 * @property string|null $reason
 * @property int|null $heldby
 * @property \Illuminate\Support\Carbon|null $heldat
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser whereAdded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser whereAddedby($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser whereByuserid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser whereCollection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser whereHeldat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser whereHeldby($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpamUser whereUserid($value)
 * @mixin \Eloquent
 */
class SpamUser extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'spam_users';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'added' => 'datetime',
        'heldat' => 'datetime',
    ];
}
