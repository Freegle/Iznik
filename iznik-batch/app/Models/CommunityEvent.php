<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_communityevents_table.php
 * @property int $id
 * @property int|null $userid
 * @property bool $pending
 * @property string $title
 * @property string $location
 * @property string|null $contactname
 * @property string|null $contactphone
 * @property string|null $contactemail
 * @property string|null $contacturl
 * @property string|null $description
 * @property \Illuminate\Support\Carbon $added
 * @property bool $deleted
 * @property int|null $legacyid For migration from FDv1
 * @property int|null $heldby
 * @property bool $deletedcovid Deleted as part of reopening
 * @property string|null $externalid
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereAdded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereContactemail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereContactname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereContactphone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereContacturl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereDeleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereDeletedcovid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereExternalid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereHeldby($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereLegacyid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereLocation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent wherePending($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CommunityEvent whereUserid($value)
 * @mixin \Eloquent
 */
class CommunityEvent extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'communityevents';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'pending' => 'boolean',
        'added' => 'datetime',
        'deleted' => 'boolean',
        'deletedcovid' => 'boolean',
    ];
}
