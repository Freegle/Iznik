<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Community News "area" - one per row in `authorities` (Counties and
 * Unitary Authorities), researched and delivered as one unit.
 *
 * @property int $id
 * @property int $authorityid
 * @property string $name
 * @property float $lat
 * @property float $lng
 */
class CommunityNewsArea extends Model
{
    protected $table = 'community_news_areas';
    protected $guarded = ['id'];

    protected $casts = [
        'authorityid' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
        'lastresearched' => 'datetime',
        'lastposted' => 'datetime',
        'lastemailed' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CommunityNewsItem::class, 'areaid');
    }
}
