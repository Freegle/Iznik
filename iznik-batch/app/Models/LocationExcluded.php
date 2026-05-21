<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_locations_excluded_table.php
 * @property int $id
 * @property int $locationid
 * @property int|null $groupid
 * @property int|null $userid
 * @property \Illuminate\Support\Carbon $date
 * @property bool $norfolk
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationExcluded newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationExcluded newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationExcluded query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationExcluded whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationExcluded whereGroupid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationExcluded whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationExcluded whereLocationid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationExcluded whereNorfolk($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationExcluded whereUserid($value)
 * @mixin \Eloquent
 */
class LocationExcluded extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'locations_excluded';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'norfolk' => 'boolean',
    ];
}
