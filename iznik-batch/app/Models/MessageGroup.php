<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $msgid id in the messages table
 * @property int $groupid
 * @property string|null $collection
 * @property \Illuminate\Support\Carbon $arrival
 * @property int $autoreposts How many times this message has been auto-reposted
 * @property \Illuminate\Support\Carbon|null $lastautopostwarning
 * @property \Illuminate\Support\Carbon|null $lastchaseup
 * @property bool $deleted
 * @property bool $senttoyahoo
 * @property string|null $yahoopendingid For Yahoo messages, pending id if relevant
 * @property string|null $yahooapprovedid For Yahoo messages, approved id if relevant
 * @property string|null $yahooapprove For Yahoo messages, email to trigger approve if relevant
 * @property string|null $yahooreject For Yahoo messages, email to trigger reject if relevant
 * @property int|null $approvedby Mod who approved this post (if any)
 * @property \Illuminate\Support\Carbon|null $approvedat
 * @property \Illuminate\Support\Carbon|null $rejectedat
 * @property string|null $msgtype In here for performance optimisation
 * @property-read \App\Models\User|null $approvedBy
 * @property-read \App\Models\Group $group
 * @property-read \App\Models\Message $message
 * @method static Builder<static>|MessageGroup approved()
 * @method static Builder<static>|MessageGroup newModelQuery()
 * @method static Builder<static>|MessageGroup newQuery()
 * @method static Builder<static>|MessageGroup notDeleted()
 * @method static Builder<static>|MessageGroup pending()
 * @method static Builder<static>|MessageGroup query()
 * @method static Builder<static>|MessageGroup whereApprovedat($value)
 * @method static Builder<static>|MessageGroup whereApprovedby($value)
 * @method static Builder<static>|MessageGroup whereArrival($value)
 * @method static Builder<static>|MessageGroup whereAutoreposts($value)
 * @method static Builder<static>|MessageGroup whereCollection($value)
 * @method static Builder<static>|MessageGroup whereDeleted($value)
 * @method static Builder<static>|MessageGroup whereGroupid($value)
 * @method static Builder<static>|MessageGroup whereLastautopostwarning($value)
 * @method static Builder<static>|MessageGroup whereLastchaseup($value)
 * @method static Builder<static>|MessageGroup whereMsgid($value)
 * @method static Builder<static>|MessageGroup whereMsgtype($value)
 * @method static Builder<static>|MessageGroup whereRejectedat($value)
 * @method static Builder<static>|MessageGroup whereSenttoyahoo($value)
 * @method static Builder<static>|MessageGroup whereYahooapprove($value)
 * @method static Builder<static>|MessageGroup whereYahooapprovedid($value)
 * @method static Builder<static>|MessageGroup whereYahoopendingid($value)
 * @method static Builder<static>|MessageGroup whereYahooreject($value)
 * @mixin \Eloquent
 */
class MessageGroup extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'messages_groups';
    protected $primaryKey = 'msgid';
    public $incrementing = FALSE;
    protected $guarded = [];
    public $timestamps = FALSE;

    public const COLLECTION_INCOMING = 'Incoming';
    public const COLLECTION_PENDING = 'Pending';
    public const COLLECTION_APPROVED = 'Approved';
    public const COLLECTION_SPAM = 'Spam';
    public const COLLECTION_QUEUED_YAHOO = 'QueuedYahooUser';
    public const COLLECTION_REJECTED = 'Rejected';
    public const COLLECTION_QUEUED_USER = 'QueuedUser';

    protected $casts = [
        'arrival' => 'datetime',
        'lastautopostwarning' => 'datetime',
        'lastchaseup' => 'datetime',
        'approvedat' => 'datetime',
        'rejectedat' => 'datetime',
        'deleted' => 'boolean',
        'senttoyahoo' => 'boolean',
        'autoreposts' => 'integer',
    ];

    /**
     * Get the message.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'msgid');
    }

    /**
     * Get the group.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }

    /**
     * Get the approver.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approvedby');
    }

    /**
     * Scope to approved messages.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('collection', self::COLLECTION_APPROVED);
    }

    /**
     * Scope to pending messages.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('collection', self::COLLECTION_PENDING);
    }

    /**
     * Scope to non-deleted messages.
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('deleted', 0);
    }

    /**
     * Check if this message is approved on this group.
     */
    public function isApproved(): bool
    {
        return $this->collection === self::COLLECTION_APPROVED;
    }
}
