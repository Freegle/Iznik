<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_ratings_table.php
 * @property int $id
 * @property int|null $rater
 * @property int $ratee
 * @property string|null $rating
 * @property \Illuminate\Support\Carbon $timestamp
 * @property bool $visible
 * @property int|null $tn_rating_id
 * @property string|null $reason
 * @property string|null $text
 * @property bool $reviewrequired
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating whereRatee($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating whereRater($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating whereReviewrequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating whereText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating whereTnRatingId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Rating whereVisible($value)
 * @mixin \Eloquent
 */
class Rating extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'ratings';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
        'visible' => 'boolean',
        'reviewrequired' => 'boolean',
    ];
}
