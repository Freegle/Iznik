<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_volunteering_table.php
 * @property int $id
 * @property int|null $userid
 * @property bool $pending
 * @property string $title
 * @property bool $online
 * @property string $location
 * @property string|null $contactname
 * @property string|null $contactphone
 * @property string|null $contactemail
 * @property string|null $contacturl
 * @property string|null $description
 * @property \Illuminate\Support\Carbon $added
 * @property bool $deleted
 * @property int|null $deletedby
 * @property \Illuminate\Support\Carbon|null $askedtorenew
 * @property \Illuminate\Support\Carbon|null $renewed
 * @property bool $expired
 * @property string|null $timecommitment
 * @property int|null $heldby
 * @property bool $deletedcovid Deleted as part of reopening
 * @property string|null $externalid
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereAdded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereAskedtorenew($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereContactemail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereContactname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereContactphone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereContacturl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereDeleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereDeletedby($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereDeletedcovid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereExpired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereExternalid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereHeldby($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereLocation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereOnline($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering wherePending($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereRenewed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereTimecommitment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Volunteering whereUserid($value)
 * @mixin \Eloquent
 */
class Volunteering extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'volunteering';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'pending' => 'boolean',
        'online' => 'boolean',
        'added' => 'datetime',
        'deleted' => 'boolean',
        'askedtorenew' => 'datetime',
        'renewed' => 'datetime',
        'expired' => 'boolean',
        'deletedcovid' => 'boolean',
    ];
}
