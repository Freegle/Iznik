<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_memberships_history_table.php
 * @property int $id
 * @property int $userid
 * @property int $groupid
 * @property string $collection
 * @property \Illuminate\Support\Carbon $added
 * @property bool $processingrequired
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MembershipHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MembershipHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MembershipHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MembershipHistory whereAdded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MembershipHistory whereCollection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MembershipHistory whereGroupid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MembershipHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MembershipHistory whereProcessingrequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MembershipHistory whereUserid($value)
 * @mixin \Eloquent
 */
class MembershipHistory extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'memberships_history';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'added' => 'datetime',
        'processingrequired' => 'boolean',
    ];
}
