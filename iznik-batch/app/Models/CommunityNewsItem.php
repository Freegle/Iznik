<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single Community News nugget for an area — drip-posted to ChitChat and/or
 * bundled into the weekly email.
 *
 * @property int $id
 * @property int $areaid
 * @property string $title
 * @property string $snippet
 * @property string|null $url
 * @property string|null $source
 * @property int|null $newsfeedid
 */
class CommunityNewsItem extends Model
{
    protected $table = 'community_news_items';
    protected $guarded = ['id'];

    protected $casts = [
        'areaid' => 'integer',
        'newsfeedid' => 'integer',
        'researched_at' => 'datetime',
        'posted_at' => 'datetime',
        'emailed_at' => 'datetime',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(CommunityNewsArea::class, 'areaid');
    }
}
