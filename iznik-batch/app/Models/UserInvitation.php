<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_invitations_table.php
 * @property int $id
 * @property int $userid
 * @property string $email
 * @property \Illuminate\Support\Carbon $date
 * @property string $outcome
 * @property \Illuminate\Support\Carbon|null $outcometimestamp
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereOutcome($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereOutcometimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereUserid($value)
 * @mixin \Eloquent
 */
class UserInvitation extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_invitations';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'outcometimestamp' => 'datetime',
    ];
}
