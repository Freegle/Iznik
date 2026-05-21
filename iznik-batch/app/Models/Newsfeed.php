<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_newsfeed_table.php
 * @property int $id
 * @property \Illuminate\Support\Carbon $timestamp
 * @property \Illuminate\Support\Carbon $added
 * @property string $type
 * @property int|null $userid
 * @property int|null $imageid
 * @property int|null $msgid
 * @property int|null $replyto
 * @property int|null $groupid
 * @property int|null $eventid
 * @property int|null $volunteeringid
 * @property int|null $storyid
 * @property string|null $message
 * @property string $position
 * @property bool $reviewrequired
 * @property \Illuminate\Support\Carbon|null $deleted
 * @property int|null $deletedby
 * @property \Illuminate\Support\Carbon|null $hidden
 * @property int|null $hiddenby
 * @property bool $closed
 * @property string|null $html
 * @property bool $pinned
 * @property string|null $location
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereAdded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereClosed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereDeleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereDeletedby($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereEventid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereGroupid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereHidden($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereHiddenby($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereHtml($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereImageid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereLocation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereMsgid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed wherePinned($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereReplyto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereReviewrequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereStoryid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereUserid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Newsfeed whereVolunteeringid($value)
 * @mixin \Eloquent
 */
class Newsfeed extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'newsfeed';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
        'added' => 'datetime',
        'reviewrequired' => 'boolean',
        'deleted' => 'datetime',
        'hidden' => 'datetime',
        'closed' => 'boolean',
        'pinned' => 'boolean',
    ];
}
