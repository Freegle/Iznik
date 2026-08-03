<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Community News "area" — a cluster of neighbouring communitynews-enabled
 * Freegle groups, researched and delivered as one unit.
 *
 * @property int $id
 * @property int $anchorgroupid
 * @property string $name
 * @property float $lat
 * @property float $lng
 * @property array $groupids
 * @property int $groupcount
 */
class CommunityNewsArea extends Model
{
    protected $table = 'community_news_areas';
    protected $guarded = ['id'];

    protected $casts = [
        'anchorgroupid' => 'integer',
        'groupids' => 'array',
        'lat' => 'float',
        'lng' => 'float',
        'groupcount' => 'integer',
        'lastresearched' => 'datetime',
        'lastposted' => 'datetime',
        'lastemailed' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CommunityNewsItem::class, 'areaid');
    }
}
