<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_teams_members_table.php
 * @property int $id
 * @property int $userid
 * @property int $teamid
 * @property \Illuminate\Support\Carbon $added
 * @property string|null $description
 * @property string|null $nameoverride
 * @property string|null $imageoverride
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereAdded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereImageoverride($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereNameoverride($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereTeamid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeamMember whereUserid($value)
 * @mixin \Eloquent
 */
class TeamMember extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'teams_members';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'added' => 'datetime',
    ];
}
