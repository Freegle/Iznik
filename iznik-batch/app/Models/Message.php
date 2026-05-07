<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $table = 'messages';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    // Email address validation regex — kept identical to iznik-server
    // Message::EMAIL_REGEXP so anything written by either codebase is accepted
    // by the other.
    public const EMAIL_REGEXP = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i';

    // Source types
    public const SOURCE_EMAIL = 'Email';
    public const SOURCE_PLATFORM = 'Platform';

    // Message types
    public const TYPE_OFFER = 'Offer';
    public const TYPE_TAKEN = 'Taken';
    public const TYPE_WANTED = 'Wanted';
    public const TYPE_RECEIVED = 'Received';
    public const TYPE_ADMIN = 'Admin';
    public const TYPE_OTHER = 'Other';

    // Outcome types (for messages_outcomes table)
    public const OUTCOME_TAKEN = 'Taken';
    public const OUTCOME_RECEIVED = 'Received';
    public const OUTCOME_WITHDRAWN = 'Withdrawn';
    public const OUTCOME_EXPIRED = 'Expired';

    /**
     * Keywords for message type detection.
     * Includes common misspellings and Welsh translations.
     */
    public const TYPE_KEYWORDS = [
        self::TYPE_OFFER => [
            'ofer', 'offr', 'offrer', 'ffered', 'offfered', 'offrered', 'offered', 'offeer', 'cynnig', 'offred',
            'offer', 'offering', 'reoffer', 're offer', 're-offer', 'reoffered', 're offered', 're-offered',
            'offfer', 'offeed', 'available',
        ],
        self::TYPE_TAKEN => [
            'collected', 'take', 'stc', 'gone', 'withdrawn', 'ta ke n', 'promised',
            'cymeryd', 'cymerwyd', 'takln', 'taken', 'cymryd',
        ],
        self::TYPE_WANTED => [
            'wnted', 'requested', 'rquested', 'request', 'would like', 'want',
            'anted', 'wated', 'need', 'needed', 'wamted', 'require', 'required', 'watnted', 'wented',
            'sought', 'seeking', 'eisiau', 'wedi eisiau', 'eisiau', 'wnated', 'wanted', 'looking', 'waned',
        ],
        self::TYPE_RECEIVED => [
            'recieved', 'reiceved', 'receved', 'rcd', 'rec\'d', 'recevied',
            'receive', 'derbynewid', 'derbyniwyd', 'received', 'recivered',
        ],
        self::TYPE_ADMIN => ['admin', 'sn'],
    ];

    /**
     * Determine message type from subject line.
     * Returns the type with the earliest keyword match.
     */
    public static function determineType(?string $subject): string
    {
        if ($subject === null || $subject === '') {
            return self::TYPE_OTHER;
        }

        $type = self::TYPE_OTHER;
        $pos = PHP_INT_MAX;

        foreach (self::TYPE_KEYWORDS as $keyword => $vals) {
            foreach ($vals as $val) {
                if (preg_match('/\b(' . preg_quote($val, '/') . ')\b/i', $subject, $matches, PREG_OFFSET_CAPTURE)) {
                    if ($matches[1][1] < $pos) {
                        // We want the match earliest in the string - handles cases like "Offerton"
                        $type = $keyword;
                        $pos = $matches[1][1];
                    }
                }
            }
        }

        return $type;
    }

    protected $casts = [
        'arrival' => 'datetime',
        'date' => 'datetime',
        'deadline' => 'date',
        'deleted' => 'datetime',
        'lat' => 'decimal:6',
        'lng' => 'decimal:6',
        'availablenow' => 'boolean',
    ];

    /**
     * Get the message's groups.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'messages_groups', 'msgid', 'groupid')
            ->withPivot(['collection', 'arrival', 'approvedby', 'deleted']);
    }

    /**
     * Get the message's outcomes.
     */
    public function outcomes(): HasMany
    {
        return $this->hasMany(MessageOutcome::class, 'msgid');
    }

    /**
     * Get the message's attachments.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class, 'msgid');
    }

    /**
     * Get the user who posted this message.
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fromuser');
    }

    /**
     * Get chat messages referencing this message.
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'refmsgid');
    }

    /**
     * Scope to approved messages.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereHas('groups', function ($q) {
            $q->where('messages_groups.collection', 'Approved');
        });
    }

    /**
     * Scope to non-deleted messages.
     */
    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->whereNull('deleted');
    }

    /**
     * Scope to messages with location data.
     */
    public function scopeWithLocation(Builder $query): Builder
    {
        return $query->whereNotNull('lat')->whereNotNull('lng');
    }

    /**
     * Scope to offers.
     */
    public function scopeOffers(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_OFFER);
    }

    /**
     * Scope to wanted posts.
     */
    public function scopeWanted(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_WANTED);
    }

    /**
     * Scope to messages that have reached their deadline.
     */
    public function scopeDeadlineReached(Builder $query): Builder
    {
        return $query->whereNotNull('deadline')
            ->whereDate('deadline', '<', now());
    }

    /**
     * Scope to recent messages.
     */
    public function scopeRecent(Builder $query, int $days = 31): Builder
    {
        return $query->where('arrival', '>=', now()->subDays($days));
    }

    /**
     * Check if message is an offer.
     */
    public function isOffer(): bool
    {
        return $this->type === self::TYPE_OFFER;
    }

    /**
     * Check if message is a wanted post.
     */
    public function isWanted(): bool
    {
        return $this->type === self::TYPE_WANTED;
    }

    /**
     * Check if message has been taken/received.
     */
    public function hasSuccessfulOutcome(): bool
    {
        return $this->outcomes()
            ->whereIn('outcome', [self::OUTCOME_TAKEN, self::OUTCOME_RECEIVED])
            ->exists();
    }
}
