<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_locations_spatial_table.php
 * @property int $locationid
 * @property string $geometry
 * @property-read \App\Models\Location $location
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationSpatial newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationSpatial newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationSpatial query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationSpatial whereGeometry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LocationSpatial whereLocationid($value)
 * @mixin \Eloquent
 */
class LocationSpatial extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'locations_spatial';
    protected $primaryKey = 'locationid';
    public $incrementing = false;
    protected $guarded = [];
    public $timestamps = false;

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'locationid');
    }
}
