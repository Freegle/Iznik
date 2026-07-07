<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_stories_table.php
 * @property int $id
 * @property int|null $userid
 * @property \Illuminate\Support\Carbon $date
 * @property bool $public
 * @property bool $reviewed
 * @property string $headline
 * @property string $story
 * @property bool $tweeted
 * @property bool $mailedtocentral Mailed to groups mailing list
 * @property bool|null $mailedtomembers
 * @property bool $newsletterreviewed
 * @property bool $newsletter
 * @property int|null $reviewedby
 * @property int|null $newsletterreviewedby
 * @property \Illuminate\Support\Carbon|null $updated
 * @property bool $fromnewsfeed
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereFromnewsfeed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereHeadline($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereMailedtocentral($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereMailedtomembers($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereNewsletter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereNewsletterreviewed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereNewsletterreviewedby($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory wherePublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereReviewed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereReviewedby($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereStory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereTweeted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereUpdated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStory whereUserid($value)
 * @mixin \Eloquent
 */
class UserStory extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_stories';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'public' => 'boolean',
        'reviewed' => 'boolean',
        'tweeted' => 'boolean',
        'mailedtocentral' => 'boolean',
        'mailedtomembers' => 'boolean',
        'newsletterreviewed' => 'boolean',
        'newsletter' => 'boolean',
        'updated' => 'datetime',
        'fromnewsfeed' => 'boolean',
    ];
}
