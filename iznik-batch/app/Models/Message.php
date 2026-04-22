<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log as Logger;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id Unique iD
 * @property \Illuminate\Support\Carbon $arrival When this message arrived at our server
 * @property \Illuminate\Support\Carbon|null $date When this message was created, e.g. Date header
 * @property \Illuminate\Support\Carbon|null $deleted When this message was deleted
 * @property int|null $heldby If this message is held by a moderator
 * @property string|null $source Source of incoming message
 * @property string|null $sourceheader Any source header, e.g. X-Freegle-Source
 * @property string|null $fromip IP we think this message came from
 * @property string|null $fromcountry fromip geocoded to country
 * @property string $message The unparsed message
 * @property int|null $fromuser
 * @property string|null $envelopefrom
 * @property string|null $fromname
 * @property string|null $fromaddr
 * @property string|null $envelopeto
 * @property string|null $replyto
 * @property string|null $subject
 * @property string|null $suggestedsubject
 * @property string|null $type For reuse groups, the message categorisation
 * @property string|null $messageid
 * @property string|null $tnpostid If this message came from Trash Nothing, the unique post ID
 * @property string|null $textbody
 * @property string|null $htmlbody
 * @property int $retrycount We might fail to route, and later retry
 * @property string|null $retrylastfailure
 * @property string|null $spamtype
 * @property string|null $spamreason Why we think this message may be spam
 * @property numeric|null $lat
 * @property numeric|null $lng
 * @property int|null $locationid
 * @property int|null $editedby
 * @property string|null $editedat
 * @property int $availableinitially
 * @property bool $availablenow
 * @property string|null $lastroute
 * @property int $deliverypossible
 * @property \Illuminate\Support\Carbon|null $deadline
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MessageAttachment> $attachments
 * @property-read int|null $attachments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChatMessage> $chatMessages
 * @property-read int|null $chat_messages_count
 * @property-read \App\Models\User|null $fromUser
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Group> $groups
 * @property-read int|null $groups_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\MessageOutcome> $outcomes
 * @property-read int|null $outcomes_count
 * @method static Builder<static>|Message approved()
 * @method static Builder<static>|Message deadlineReached()
 * @method static Builder<static>|Message newModelQuery()
 * @method static Builder<static>|Message newQuery()
 * @method static Builder<static>|Message notDeleted()
 * @method static Builder<static>|Message offers()
 * @method static Builder<static>|Message query()
 * @method static Builder<static>|Message recent(int $days = 31)
 * @method static Builder<static>|Message wanted()
 * @method static Builder<static>|Message whereArrival($value)
 * @method static Builder<static>|Message whereAvailableinitially($value)
 * @method static Builder<static>|Message whereAvailablenow($value)
 * @method static Builder<static>|Message whereDate($value)
 * @method static Builder<static>|Message whereDeadline($value)
 * @method static Builder<static>|Message whereDeleted($value)
 * @method static Builder<static>|Message whereDeliverypossible($value)
 * @method static Builder<static>|Message whereEditedat($value)
 * @method static Builder<static>|Message whereEditedby($value)
 * @method static Builder<static>|Message whereEnvelopefrom($value)
 * @method static Builder<static>|Message whereEnvelopeto($value)
 * @method static Builder<static>|Message whereFromaddr($value)
 * @method static Builder<static>|Message whereFromcountry($value)
 * @method static Builder<static>|Message whereFromip($value)
 * @method static Builder<static>|Message whereFromname($value)
 * @method static Builder<static>|Message whereFromuser($value)
 * @method static Builder<static>|Message whereHeldby($value)
 * @method static Builder<static>|Message whereHtmlbody($value)
 * @method static Builder<static>|Message whereId($value)
 * @method static Builder<static>|Message whereLastroute($value)
 * @method static Builder<static>|Message whereLat($value)
 * @method static Builder<static>|Message whereLng($value)
 * @method static Builder<static>|Message whereLocationid($value)
 * @method static Builder<static>|Message whereMessage($value)
 * @method static Builder<static>|Message whereMessageid($value)
 * @method static Builder<static>|Message whereReplyto($value)
 * @method static Builder<static>|Message whereRetrycount($value)
 * @method static Builder<static>|Message whereRetrylastfailure($value)
 * @method static Builder<static>|Message whereSource($value)
 * @method static Builder<static>|Message whereSourceheader($value)
 * @method static Builder<static>|Message whereSpamreason($value)
 * @method static Builder<static>|Message whereSpamtype($value)
 * @method static Builder<static>|Message whereSubject($value)
 * @method static Builder<static>|Message whereSuggestedsubject($value)
 * @method static Builder<static>|Message whereTextbody($value)
 * @method static Builder<static>|Message whereTnpostid($value)
 * @method static Builder<static>|Message whereType($value)
 * @method static Builder<static>|Message withLocation()
 * @mixin \Eloquent
 */
class Message extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'messages';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

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
            'ofer',
            'offr',
            'offrer',
            'ffered',
            'offfered',
            'offrered',
            'offered',
            'offeer',
            'cynnig',
            'offred',
            'offer',
            'offering',
            'reoffer',
            're offer',
            're-offer',
            'reoffered',
            're offered',
            're-offered',
            'offfer',
            'offeed',
            'available',
        ],
        self::TYPE_TAKEN => [
            'collected',
            'take',
            'stc',
            'gone',
            'withdrawn',
            'ta ke n',
            'promised',
            'cymeryd',
            'cymerwyd',
            'takln',
            'taken',
            'cymryd',
        ],
        self::TYPE_WANTED => [
            'wnted',
            'requested',
            'rquested',
            'request',
            'would like',
            'want',
            'anted',
            'wated',
            'need',
            'needed',
            'wamted',
            'require',
            'required',
            'watnted',
            'wented',
            'sought',
            'seeking',
            'eisiau',
            'wedi eisiau',
            'eisiau',
            'wnated',
            'wanted',
            'looking',
            'waned',
        ],
        self::TYPE_RECEIVED => [
            'recieved',
            'reiceved',
            'receved',
            'rcd',
            'rec\'d',
            'recevied',
            'receive',
            'derbynewid',
            'derbyniwyd',
            'received',
            'recivered',
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
     * Check if message has any outcome.
     */
    public function hasOutcome(): bool
    {
        return $this->outcomes()->exists();
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

    /**
     * Check if a comment is a generic/boilerplate phrase not worth storing.
     */
    public function dullComment(?string $comment): bool
    {
        $dull = TRUE;

        $comment = $comment ? trim($comment) : '';

        if (strlen($comment)) {
            $dull = FALSE;

            foreach (
                [
                    'Sorry, this is no longer available.',
                    'Thanks, this has now been taken.',
                    "Thanks, I'm no longer looking for this.",
                    'Sorry, this has now been taken.',
                    'Thanks for the interest, but this has now been taken.',
                    'Thanks, these have now been taken.',
                    'Thanks, this has now been received.',
                    'Sorry, this is no longer available',
                    'Withdrawn on user unsubscribe',
                ] as $bland
            ) {
                if (strcmp($comment, $bland) === 0) {
                    $dull = TRUE;
                }
            }
        }

        return $dull;
    }

    /**
     * Return the comment if it is interesting (non-generic), otherwise null.
     */
    public function interestingComment(?string $comment): ?string
    {
        return !$this->dullComment($comment) ? $comment : NULL;
    }

    /**
     * Record a withdrawal outcome for this message.
     *
     * @param string|null $comment  Optional comment from the user.
     * @param int|null    $happiness  Optional happiness rating.
     * @param int|null    $byUserId  ID of the user performing the withdrawal (null for system/batch).
     */
    public function withdraw(?string $comment, ?int $happiness, ?int $byUserId = NULL): void
    {
        $intcomment = $this->interestingComment($comment);

        Logger::info("TN-SYNC-TRACE [WRITE] table=messages_outcomes_intended op=delete where=msgid={$this->id}");
        // MessageOutcomeIntended::where('msgid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing

        $messageOutcome = new MessageOutcome();
        $messageOutcome->msgid = $this->id;
        $messageOutcome->outcome = self::OUTCOME_WITHDRAWN;
        $messageOutcome->happiness = $happiness;
        $messageOutcome->comments = $intcomment;
        Logger::info("TN-SYNC-TRACE [WRITE] table=messages_outcomes op=insert set=msgid={$this->id},outcome=" . Message::OUTCOME_WITHDRAWN . ",happiness=" . ($happiness ?? 'NULL') . ",comments=len=" . strlen($intcomment ?? ''));
        // $messageOutcome->save();  // TRACE: commented out for port testing

        $log = new Log();
        $log->timestamp = now();
        $log->type = 'Message';
        $log->subtype = 'Outcome';
        $log->msgid = $this->id;
        $log->user = $this->fromuser;
        $log->byuser = $byUserId;
        $log->text = $intcomment ? "Withdrawn: $comment" : 'Withdrawn';
        Logger::info("TN-SYNC-TRACE [WRITE] table=logs op=insert set=type=Message,subtype=Outcome,msgid={$this->id},user={$this->fromuser},byuser=" . ($byUserId ?? 'NULL') . ",text=len=" . strlen($intcomment ? "Withdrawn: $comment" : 'Withdrawn'));
        // $log->save();  // TRACE: commented out for port testing
    }
}
