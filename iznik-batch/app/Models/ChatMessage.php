<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int $chatid
 * @property int $userid From
 * @property string $type
 * @property string|null $reportreason
 * @property int|null $refmsgid
 * @property int|null $refchatid
 * @property int|null $imageid
 * @property \Illuminate\Support\Carbon $date
 * @property string|null $message
 * @property bool $platform Whether this was created on the platform vs email
 * @property bool $seenbyall
 * @property bool $mailedtoall
 * @property bool $reviewrequired Whether a volunteer should review before it's passed on
 * @property int|null $reviewedby User id of volunteer who reviewed it
 * @property bool $reviewrejected
 * @property int|null $spamscore SpamAssassin score for mail replies
 * @property int|null $scheduleid
 * @property bool|null $replyexpected
 * @property bool $replyreceived
 * @property bool $processingrequired
 * @property bool $processingsuccessful
 * @property bool $confirmrequired
 * @property bool $deleted
 * @property-read \App\Models\ChatRoom $chatRoom
 * @property-read \App\Models\ChatImage|null $image
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChatImage> $images
 * @property-read int|null $images_count
 * @property-read \App\Models\Message|null $refMessage
 * @property-read \App\Models\User|null $reviewer
 * @property-read \App\Models\User $user
 * @method static Builder<static>|ChatMessage expectingReply()
 * @method static Builder<static>|ChatMessage newModelQuery()
 * @method static Builder<static>|ChatMessage newQuery()
 * @method static Builder<static>|ChatMessage query()
 * @method static Builder<static>|ChatMessage recent(int $days = 31)
 * @method static Builder<static>|ChatMessage requiringReview()
 * @method static Builder<static>|ChatMessage unmailed()
 * @method static Builder<static>|ChatMessage unseen()
 * @method static Builder<static>|ChatMessage visible()
 * @method static Builder<static>|ChatMessage whereChatid($value)
 * @method static Builder<static>|ChatMessage whereConfirmrequired($value)
 * @method static Builder<static>|ChatMessage whereDate($value)
 * @method static Builder<static>|ChatMessage whereDeleted($value)
 * @method static Builder<static>|ChatMessage whereFacebookid($value)
 * @method static Builder<static>|ChatMessage whereId($value)
 * @method static Builder<static>|ChatMessage whereImageid($value)
 * @method static Builder<static>|ChatMessage whereMailedtoall($value)
 * @method static Builder<static>|ChatMessage whereMessage($value)
 * @method static Builder<static>|ChatMessage wherePlatform($value)
 * @method static Builder<static>|ChatMessage whereProcessingrequired($value)
 * @method static Builder<static>|ChatMessage whereProcessingsuccessful($value)
 * @method static Builder<static>|ChatMessage whereRefchatid($value)
 * @method static Builder<static>|ChatMessage whereRefmsgid($value)
 * @method static Builder<static>|ChatMessage whereReplyexpected($value)
 * @method static Builder<static>|ChatMessage whereReplyreceived($value)
 * @method static Builder<static>|ChatMessage whereReportreason($value)
 * @method static Builder<static>|ChatMessage whereReviewedby($value)
 * @method static Builder<static>|ChatMessage whereReviewrejected($value)
 * @method static Builder<static>|ChatMessage whereReviewrequired($value)
 * @method static Builder<static>|ChatMessage whereScheduleid($value)
 * @method static Builder<static>|ChatMessage whereSeenbyall($value)
 * @method static Builder<static>|ChatMessage whereSpamscore($value)
 * @method static Builder<static>|ChatMessage whereType($value)
 * @method static Builder<static>|ChatMessage whereUserid($value)
 * @mixin \Eloquent
 */
class ChatMessage extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'chat_messages';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    /**
     * Reasons a message was dropped during processing (chat_messages.processingfailreason).
     * A dropped message is never notified to the recipient, so support needs to be able to
     * say why rather than leave it looking like the sender never wrote.
     */
    public const PROCESSFAIL_SPAMMER = 'Spammer';
    public const PROCESSFAIL_BANNED_IN_COMMON = 'BannedInCommonGroups';
    // The replier was asked whether they still wanted an item that looked damaged or too big
    // to carry, and never answered.
    public const PROCESSFAIL_NOT_CONFIRMED = 'ReplyNotConfirmed';
    // ...and answered that they did not want it. Set by the Go API when they answer.
    public const PROCESSFAIL_REPLY_WITHDRAWN = 'ReplyWithdrawn';
    // The post was taken or withdrawn while the reply was waiting for its sender's answer.
    public const PROCESSFAIL_POST_GONE = 'ReplyPostGone';

    public const TYPE_DEFAULT = 'Default';
    public const TYPE_SYSTEM = 'System';
    public const TYPE_MODMAIL = 'ModMail';
    public const TYPE_INTERESTED = 'Interested';
    public const TYPE_PROMISED = 'Promised';
    public const TYPE_RENEGED = 'Reneged';
    public const TYPE_COMPLETED = 'Completed';
    public const TYPE_IMAGE = 'Image';
    public const TYPE_ADDRESS = 'Address';
    public const TYPE_NUDGE = 'Nudge';
    public const TYPE_REMINDER = 'Reminder';
    public const TYPE_REPORTEDUSER = 'ReportedUser';

    /**
     * A question from Freegle with tappable answers. The question text is in
     * `message` like any other message, so everything that renders chat renders
     * this; the options and the answer live in the chat_prompts side table.
     */
    public const TYPE_PROMPT = 'Prompt';

    // Review reason values (reportreason column).
    public const REVIEW_USER = 'User';

    protected $casts = [
        'date' => 'datetime',
        'seenbyall' => 'boolean',
        'mailedtoall' => 'boolean',
        'reviewrequired' => 'boolean',
        'reviewrejected' => 'boolean',
        'replyexpected' => 'boolean',
        'replyreceived' => 'boolean',
        'processingrequired' => 'boolean',
        'processingsuccessful' => 'boolean',
        'confirmrequired' => 'boolean',
        'deleted' => 'boolean',
        'platform' => 'boolean',
    ];

    /**
     * Get the chat room.
     */
    public function chatRoom(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'chatid');
    }

    /**
     * Get the sender.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Get the referenced message.
     */
    public function refMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'refmsgid');
    }

    /**
     * Get the reviewer.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewedby');
    }

    /**
     * Get the image if this is an image message.
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(ChatImage::class, 'imageid');
    }

    /**
     * Get images attached to this message.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ChatImage::class, 'chatmsgid');
    }

    /**
     * Scope to visible messages (not review-rejected).
     */
    /**
     * SQL that is true for a chat message a reply confirmation (chat_prompts kind confirm_reply) is holding or has
     * refused, for the chat_messages row aliased $m: the reply the question names, and what its sender wrote in that
     * chat while the question was open. Such a message is not a delivered reply, so counts of replies leave it out.
     * A yes releases them, so only a question still open or answered other than yes holds them. The dates bound the
     * further messages to the time the question was open, with 2 seconds of slack because MySQL rounds the Go clock up
     * to the next second, so what the sender writes after a no, or after the three
     * days, is an ordinary message. $openOnly leaves out the questions that were answered, for what is waiting now.
     * The Go twin is utils.ReplyHeldByQuestionSQL; the two are kept the same.
     */
    public static function heldByQuestionSql(string $m, bool $openOnly = false): string
    {
        $state = $openOnly ? 'cpr.answered_at IS NULL' : "(cpr.answered_at IS NULL OR cpr.answer <> 'yes')";

        return "(EXISTS (SELECT 1 FROM chat_prompts cpr WHERE cpr.heldchatmsgid = $m.id AND cpr.kind = 'confirm_reply' AND $state)"
            ." OR EXISTS (SELECT 1 FROM chat_prompts cpr INNER JOIN chat_messages cph ON cph.id = cpr.heldchatmsgid"
            ." WHERE cpr.userid = $m.userid AND cpr.kind = 'confirm_reply' AND cph.chatid = $m.chatid AND cph.id < $m.id AND $state"
            ." AND $m.date <= COALESCE(cpr.answered_at, cpr.expires_at, '9999-01-01 00:00:00') + INTERVAL 2 SECOND))";
    }

    /**
     * The member a reply question is for, while the poster is not to be told of it: the chat message is a
     * confirm_reply question that no delivery has made successful. Notifications about it go to that member and to
     * nobody else. Null for any other message, and for a question that is shown to both (delivered with its reply).
     */
    public static function questionAskedOnlyOf(int $messageId): ?int
    {
        $userid = DB::table('chat_prompts as cp')
            ->join('chat_messages as cm', 'cm.id', '=', 'cp.chatmsgid')
            ->where('cp.chatmsgid', $messageId)
            ->where('cp.kind', 'confirm_reply')
            ->whereNotNull('cp.userid')
            ->where('cm.processingsuccessful', 0)
            ->value('cp.userid');

        return $userid === null ? null : (int) $userid;
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('reviewrejected', 0)
            ->where('reviewrequired', 0)
            ->where('processingsuccessful', 1);
    }

    /**
     * Scope to messages requiring review.
     */
    public function scopeRequiringReview(Builder $query): Builder
    {
        return $query->where('reviewrequired', 1);
    }

    /**
     * Scope to messages not seen by all.
     */
    public function scopeUnseen(Builder $query): Builder
    {
        return $query->where('seenbyall', 0);
    }

    /**
     * Scope to messages not mailed to all.
     */
    public function scopeUnmailed(Builder $query): Builder
    {
        return $query->where('mailedtoall', 0);
    }

    /**
     * Scope to messages expecting a reply.
     */
    public function scopeExpectingReply(Builder $query): Builder
    {
        return $query->where('replyexpected', 1)
            ->where('replyreceived', 0);
    }

    /**
     * Scope to recent messages.
     */
    public function scopeRecent(Builder $query, int $days = 31): Builder
    {
        return $query->where('date', '>=', now()->subDays($days));
    }

    /**
     * Check if this message is visible to users.
     */
    public function isVisible(): bool
    {
        return !$this->reviewrejected
            && !$this->reviewrequired
            && $this->processingsuccessful;
    }

    /**
     * Check if this is a system message.
     */
    public function isSystemMessage(): bool
    {
        return $this->type === self::TYPE_SYSTEM;
    }

    /**
     * Check if this message was sent from the platform (not email).
     */
    public function isFromPlatform(): bool
    {
        return (bool) $this->platform;
    }

    /**
     * Return per-group counts of chat messages pending review for the given moderator.
     *
     * For each group the moderator actively mods, counts how many User2User chat messages
     * have reviewrequired=1 and have not yet been rejected, where either:
     *   (a) the recipient is a member of that group, or
     *   (b) the sender is a member of that group and the recipient is not on any Freegle group.
     *
     * When $other=true, counts messages that are currently held instead of unheld.
     *
     * Ported from the legacy V1 PHP ChatMessage::getReviewCountByGroup().
     *
     * @param User|null $me    The moderator. NULL returns an empty array.
     * @param bool      $other When true, count held messages instead of unreviewed ones.
     * @return array<array{groupid: int, count: int}>
     */
    public function getReviewCountByGroup(?User $me, bool $other = false): array
    {
        if (!$me) {
            return [];
        }

        $widerReview = $me->widerReview();

        $groupIds = [];
        foreach ($me->getModeratorships() as $mod) {
            if ($me->activeModForGroup($mod)) {
                $groupIds[] = $mod;
            }
        }

        if (empty($groupIds)) {
            return [];
        }

        $cutoff = now()->subDays(31);

        // CASE expression for the "other user" in the chat room (i.e. the recipient).
        $otherUser = 'CASE WHEN chat_messages.userid = chat_rooms.user1 THEN chat_rooms.user2 ELSE chat_rooms.user1 END';

        // Part 1: messages where the recipient is a member of one of our modded groups.
        $part1 = DB::table('chat_messages')
            ->select(['chat_messages.id', 'memberships.groupid'])
            ->leftJoin('chat_messages_held', 'chat_messages_held.msgid', '=', 'chat_messages.id')
            ->join('chat_rooms', 'chat_rooms.id', '=', 'chat_messages.chatid')
            ->join('memberships', function ($join) use ($groupIds, $otherUser) {
                // keep-raw: the join condition compares a column to a CASE WHEN expression
                // over two other columns - the builder's join clause has no method that
                // takes an arbitrary expression on the right-hand side, only column-to-column
                // (on) or column-to-bound-value (where) comparisons.
                $join->whereRaw("memberships.userid = {$otherUser}")
                    ->whereIn('memberships.groupid', $groupIds);
            })
            ->join('groups', function ($join) {
                $join->on('memberships.groupid', '=', 'groups.id')
                    ->where('groups.type', Group::TYPE_FREEGLE);
            })
            ->where('chat_messages.reviewrequired', 1)
            ->where('chat_messages.reviewrejected', 0)
            ->where('chat_messages.date', '>', $cutoff);

        // Part 2: messages where the recipient is not on any Freegle group, but the sender is in one of our groups.
        $part2 = DB::table('chat_messages')
            ->select(['chat_messages.id', 'm2.groupid'])
            ->leftJoin('chat_messages_held', 'chat_messages_held.msgid', '=', 'chat_messages.id')
            ->join('chat_rooms', 'chat_rooms.id', '=', 'chat_messages.chatid')
            ->leftJoin('memberships as m1', function ($join) use ($otherUser) {
                // keep-raw: same CASE WHEN join condition as part1 above - no builder
                // method takes an arbitrary expression as the join comparison target.
                $join->whereRaw("m1.userid = {$otherUser}");
            })
            ->leftJoin('groups', function ($join) {
                $join->on('m1.groupid', '=', 'groups.id')
                    ->where('groups.type', Group::TYPE_FREEGLE);
            })
            ->join('memberships as m2', function ($join) use ($groupIds) {
                $join->on('m2.userid', '=', 'chat_messages.userid')
                    ->whereIn('m2.groupid', $groupIds);
            })
            ->where('chat_messages.reviewrequired', 1)
            ->where('chat_messages.reviewrejected', 0)
            ->where('chat_messages.date', '>', $cutoff)
            ->whereNull('m1.id');

        foreach ([$part1, $part2] as $part) {
            if ($other) {
                $part->whereNotNull('chat_messages_held.userid');
            } else {
                $part->whereNull('chat_messages_held.userid');
            }
        }

        $query = $part1->union($part2);

        // Part 3: wider-review held messages (only when moderator has wider review and $other=true).
        if ($widerReview && $other) {
            $part3 = DB::table('chat_messages')
                ->select(['chat_messages.id', 'memberships.groupid'])
                ->join('chat_rooms', 'chat_rooms.id', '=', 'chat_messages.chatid')
                ->leftJoin('chat_messages_held', 'chat_messages.id', '=', 'chat_messages_held.msgid')
                ->join('memberships', function ($join) use ($otherUser) {
                    // keep-raw: same CASE WHEN join condition as part1/part2 above.
                    $join->whereRaw("memberships.userid = {$otherUser}");
                })
                ->join('groups', function ($join) {
                    $join->on('memberships.groupid', '=', 'groups.id')
                        ->where('groups.type', Group::TYPE_FREEGLE);
                })
                ->where('chat_messages.reviewrequired', 1)
                ->where('chat_messages.reviewrejected', 0)
                ->where('chat_messages.date', '>', $cutoff)
                ->whereJsonContains('groups.settings->widerchatreview', 1)
                ->whereNull('chat_messages_held.id')
                ->where('chat_messages.reportreason', '!=', self::REVIEW_USER);

            $query = $query->union($part3);
        }

        $counts = $query->orderBy('groupid')->get();

        // The same message might appear in the query results multiple times if the recipient is on multiple
        // groups that we mod. We only want to count it once. The order here matches that in
        // ChatRoom::getMessagesForReview.
        $usedMsgs = [];
        $seenGroups = [];

        foreach ($counts as $count) {
            $usedMsgs[$count->id] = $count->groupid;
            $seenGroups[$count->groupid] = $count->groupid;
        }

        $showcounts = [];

        foreach ($seenGroups as $groupId) {
            $count = 0;

            foreach ($usedMsgs as $msgGroupId) {
                if ($msgGroupId == $groupId) {
                    $count++;
                }
            }

            $showcounts[] = [
                'groupid' => $groupId,
                'count'   => $count,
            ];
        }

        return $showcounts;
    }
}
