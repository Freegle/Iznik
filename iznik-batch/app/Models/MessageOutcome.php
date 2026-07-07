<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property \Illuminate\Support\Carbon $timestamp
 * @property int $msgid
 * @property string $outcome
 * @property int|null $happiness
 * @property string|null $comments
 * @property int $reviewed
 * @property-read \App\Models\Message $message
 * @method static Builder<static>|MessageOutcome expired()
 * @method static Builder<static>|MessageOutcome newModelQuery()
 * @method static Builder<static>|MessageOutcome newQuery()
 * @method static Builder<static>|MessageOutcome query()
 * @method static Builder<static>|MessageOutcome repost()
 * @method static Builder<static>|MessageOutcome successful()
 * @method static Builder<static>|MessageOutcome whereComments($value)
 * @method static Builder<static>|MessageOutcome whereHappiness($value)
 * @method static Builder<static>|MessageOutcome whereId($value)
 * @method static Builder<static>|MessageOutcome whereMsgid($value)
 * @method static Builder<static>|MessageOutcome whereOutcome($value)
 * @method static Builder<static>|MessageOutcome whereReviewed($value)
 * @method static Builder<static>|MessageOutcome whereTimestamp($value)
 * @method static Builder<static>|MessageOutcome withdrawn()
 * @mixin \Eloquent
 */
class MessageOutcome extends Model
{
    protected $table = 'messages_outcomes';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    public const OUTCOME_TAKEN = 'Taken';
    public const OUTCOME_RECEIVED = 'Received';
    public const OUTCOME_WITHDRAWN = 'Withdrawn';
    public const OUTCOME_REPOST = 'Repost';
    public const OUTCOME_EXPIRED = 'Expired';
    public const OUTCOME_PARTIAL = 'Partial';

    protected $casts = [
        'timestamp' => 'datetime',
        'happiness' => 'integer',
    ];

    /**
     * Get the message.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'msgid');
    }

    /**
     * Scope to successful outcomes (taken/received).
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->whereIn('outcome', [self::OUTCOME_TAKEN, self::OUTCOME_RECEIVED]);
    }

    /**
     * Scope to expired outcomes.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('outcome', self::OUTCOME_EXPIRED);
    }

    /**
     * Scope to repost outcomes.
     */
    public function scopeRepost(Builder $query): Builder
    {
        return $query->where('outcome', self::OUTCOME_REPOST);
    }

    /**
     * Scope to withdrawn outcomes.
     */
    public function scopeWithdrawn(Builder $query): Builder
    {
        return $query->where('outcome', self::OUTCOME_WITHDRAWN);
    }

    /**
     * Check if this is a successful outcome.
     */
    public function isSuccessful(): bool
    {
        return in_array($this->outcome, [self::OUTCOME_TAKEN, self::OUTCOME_RECEIVED]);
    }
}
