<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Services\ContentCheckService;
use App\Services\Lockdown\LockdownService;
use App\Services\Lockdown\LockdownHoldsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes chat messages that arrive via incoming email (processingrequired = 1).
 *
 * Mirrors V1 cron/chat_process.php + ChatMessage::process().
 *
 * Key responsibilities:
 * - Spam/ban checks for User2User chats
 * - Setting processingrequired = 0, processingsuccessful = 1 (or 0 on failure)
 * - Updating chat_roster for the sender
 * - Reopening closed chats after new activity
 */
class ChatProcessService
{
    // V1: ChatMessage::REVIEW_SPAM, REVIEW_FORCE, etc.
    private const REVIEW_SPAM = 'Spam';
    private const REVIEW_LAST = 'Last';

    /**
     * Map a ContentCheckService check identifier to the specific chat_messages
     * `reportreason` enum value, so the modtools review UI can tell the moderator
     * WHY a message was held instead of the unhelpful "failed spam checks, but we
     * don't have any more information about why". Every value here is a member of
     * the reportreason enum and is already rendered with friendly text by
     * ModChatReview.vue. Anything unmapped falls back to the generic 'Spam'.
     */
    private const CHECK_TO_REPORTREASON = [
        ContentCheckService::CHECK_CONCERN_KEYWORD => 'WorryWord',
        ContentCheckService::CHECK_PER_GROUP_WORRY => 'WorryWord',
        ContentCheckService::CHECK_MONEY           => 'Money',
        ContentCheckService::CHECK_URL             => 'Link',
        ContentCheckService::CHECK_MESSAGING_LINK  => 'Link',
        ContentCheckService::CHECK_SPAMHAUS_DBL    => 'URL on DBL',
        ContentCheckService::CHECK_EMAIL_ADDRESS   => 'Email',
        ContentCheckService::CHECK_LANGUAGE        => 'Language',
        ContentCheckService::CHECK_KNOWN_SPAMMER   => 'Referenced known spammer',
        ContentCheckService::CHECK_GREETING_SPAM   => 'Greetings spam',
    ];

    /**
     * Resolve the specific reportreason for a checkChatMessage() result.
     * Returns the generic 'Spam' for a null result or an unmapped check.
     */
    private function reportReasonForCheck(?array $result): string
    {
        $check = $result['check'] ?? null;
        return self::CHECK_TO_REPORTREASON[$check] ?? self::REVIEW_SPAM;
    }

    /** kind value lockdown_holds uses for chat rows. */
    private const HOLD_KIND = LockdownHoldsService::KIND_CHAT;

    private ContentCheckService $contentCheck;

    private LockdownService $lockdown;

    /** Messages dropped by a block keyword during the current processIncoming(). */
    private int $dropped = 0;

    public function __construct(
        ?ContentCheckService $contentCheck = null,
        ?LockdownService $lockdown = null
    ) {
        // Resolve from the container when not injected (keeps `new ChatProcessService()` working).
        $this->contentCheck = $contentCheck ?? app(ContentCheckService::class);
        $this->lockdown = $lockdown ?? app(LockdownService::class);
    }

    /**
     * Process all pending chat messages (processingrequired = 1), oldest first.
     *
     * While a lockdown holds chat, member-to-member messages wait here unprocessed; chat
     * with the volunteers keeps flowing. Once chat is lifted they are simply pending again,
     * so this processes everything the lockdown held, straight away, through the same
     * checks as any other message: a sender Support has since marked as a spammer is
     * dropped by the spammer check. Work goes in batches only to keep each query small.
     *
     * @return int Number of messages processed.
     */
    public function processIncoming(): int
    {
        // Ticks over even on a quiet run with no messages, so the presser sees this loop
        // still running (section 11.6 point 3).
        $this->lockdown->ack('chat-process');

        $count = 0;
        $this->dropped = 0;
        $lastId = 0;

        while (true) {
            $chatHeld = $this->lockdown->held('chat');
            $messages = DB::table('chat_messages')
                ->join('chat_rooms', 'chat_messages.chatid', '=', 'chat_rooms.id')
                ->where('chat_messages.processingrequired', 1)
                ->where('chat_messages.id', '>', $lastId)
                ->when($chatHeld, fn ($query) => $query->where('chat_rooms.chattype', '!=', ChatRoom::TYPE_USER2USER))
                ->orderBy('chat_messages.id', 'asc')
                ->limit(LockdownHoldsService::releaseBatchSize())
                ->select('chat_messages.*', 'chat_rooms.chattype', 'chat_rooms.user1', 'chat_rooms.user2')
                ->get();
            if ($messages->isEmpty()) {
                break;
            }
            $lastId = (int) $messages->last()->id;

            // Which of this batch the lockdown held, so their holds can be closed once they
            // go through. One query per batch; nothing at all if there has never been a lockdown.
            $held = $this->lockdown->incidentId() === null ? [] : DB::table('lockdown_holds')
                ->where('kind', self::HOLD_KIND)
                ->whereNull('outcome')
                ->whereIn('refid', $messages->pluck('id'))
                ->pluck('refid')
                ->flip()
                ->all();

            $awaiting = $this->awaitingConfirmation($messages);
            $confirmed = $this->confirmedQuestions($messages->pluck('id')->all());

            foreach ($messages as $message) {
                // Re-read per message (section 11.6 point 2): a press landing between two
                // messages of this run stops the next member-to-member one at once. held()
                // is a memory read within the five-second cache, so this costs nothing.
                if ($message->chattype === ChatRoom::TYPE_USER2USER && $this->lockdown->held('chat')) {
                    continue;
                }

                if (array_key_exists($message->id, $awaiting)) {
                    $this->holdForConfirmation((int) $message->id, $awaiting[$message->id]);
                    continue;
                }

                // A reply the replier has said yes to is not delivered after the item has gone: the outcome task
                // drops it too, but this run may land first.
                if (array_key_exists($message->id, $confirmed) && $message->refmsgid
                    && DB::table('messages_outcomes')->where('msgid', $message->refmsgid)->exists()) {
                    $this->processFailed((int) $message->id, ChatMessage::PROCESSFAIL_POST_GONE);
                    continue;
                }
                $this->lockdown->ack('chat-process');

                if ($this->processMessage($message)) {
                    $count++;
                    if (array_key_exists($message->id, $held)) {
                        // The ordinary checks may have refused it (a sender marked as a
                        // spammer, a blocked phrase), which leaves it unsuccessful.
                        $delivered = (int) DB::table('chat_messages')->where('id', $message->id)->value('processingsuccessful') === 1;
                        $this->recordHoldOutcome((int) $message->id, $delivered ? 'released' : 'rejected');
                    }
                    if (array_key_exists($message->id, $confirmed)) {
                        $this->showQuestionWithReply((int) $message->id, (int) $confirmed[$message->id]);
                    }
                }
            }
        }

        Log::info("ChatProcess: processed {$count} messages", ['dropped' => $this->dropped]);

        return $count;
    }

    /**
     * How many of the messages processed by the last processIncoming() were
     * dropped by a block keyword. Those count as processed, so this is the
     * figure the run summary needs alongside the total.
     */
    public function droppedCount(): int
    {
        return $this->dropped;
    }

    /**
     * Which of these messages are waiting for their sender to answer a question, keyed by message id, with when the
     * question expires.
     *
     * The Go API asks someone replying to an offer that looks damaged, or too big to carry, whether they still want
     * it (chat/replyconfirm.go), and names the reply in the prompt's heldchatmsgid. Until they answer, the reply is
     * left unprocessed, which keeps it from the poster: an unprocessed message is only ever shown to its sender.
     *
     * Whatever else the sender writes in that chat while the question is open waits behind it, in order, and goes
     * the same way as the reply: delivered after a yes, withdrawn after a no, dropped when the question expires.
     * Only the buttons answer the question. A message written after the question expired is not held.
     *
     * @param  Collection<int, object>  $messages  The batch: chat_messages rows.
     * @return array<int, ?string>
     */
    private function awaitingConfirmation(Collection $messages): array
    {
        $ids = $messages->pluck('id')->all();
        if (empty($ids)) {
            return [];
        }

        $awaiting = DB::table('chat_prompts')
            ->whereIn('heldchatmsgid', $ids)
            ->whereNull('answered_at')
            ->pluck('expires_at', 'heldchatmsgid')
            ->all();

        $open = DB::table('chat_prompts as cp')
            ->join('chat_messages as held', 'held.id', '=', 'cp.heldchatmsgid')
            ->where('cp.kind', 'confirm_reply')
            ->whereNull('cp.answered_at')
            ->whereIn('cp.userid', $messages->pluck('userid')->unique()->all())
            ->get(['cp.userid', 'cp.heldchatmsgid', 'cp.expires_at', 'held.chatid']);

        foreach ($messages as $message) {
            if (array_key_exists($message->id, $awaiting)) {
                continue;
            }

            foreach ($open as $question) {
                $writtenWhileOpen = $question->expires_at === null
                    || strtotime((string) $message->date) <= strtotime((string) $question->expires_at);

                if ((int) $question->userid === (int) $message->userid
                    && (int) $question->chatid === (int) $message->chatid
                    && (int) $question->heldchatmsgid < (int) $message->id
                    && $writtenWhileOpen) {
                    $awaiting[$message->id] = $question->expires_at;
                    break;
                }
            }
        }

        return $awaiting;
    }

    /**
     * The answered questions that held these replies, keyed by reply id, with the question's
     * own chat message id.
     *
     * @param  array<int>  $ids
     * @return array<int, int>
     */
    private function confirmedQuestions(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return DB::table('chat_prompts')
            ->whereIn('heldchatmsgid', $ids)
            ->whereNotNull('answered_at')
            ->pluck('chatmsgid', 'heldchatmsgid')
            ->all();
    }

    /**
     * Once a confirmed reply is delivered, let the poster see the question and the answer too,
     * in the same step, so they arrive together rather than the question first. If the ordinary
     * checks refused the reply, the question stays the replier's alone.
     *
     * Delivered is what the poster's chat fetch needs to show the reply: processed and successful,
     * and neither held for review (a Fully moderated sender, a Moderated sender whose text trips a
     * check, a message after a held one) nor dropped by a block keyword. A held or dropped reply is
     * processed and successful too, and the poster is shown nothing of it, so its question stays
     * hidden as well, and is not emailed. If a moderator approves the reply, the Go API shows the
     * question with it (chat/replyconfirm.go, revealQuestionOfApprovedReply).
     */
    private function showQuestionWithReply(int $replyId, int $questionId): void
    {
        $delivered = ChatMessage::query()->visible()->whereKey($replyId)->exists();

        if ($delivered) {
            DB::table('chat_messages')->where('id', $questionId)->update(['processingsuccessful' => 1]);
        }
    }

    /**
     * Leave a reply that is waiting for its sender's answer, or drop it once the question has
     * expired unanswered: the replier never said they still wanted the item, so the poster is
     * not sent the reply.
     */
    private function holdForConfirmation(int $id, ?string $expiresAt): void
    {
        if ($expiresAt === null || now()->lessThan($expiresAt)) {
            return;
        }

        DB::table('chat_messages')->where('id', $id)->where('processingrequired', 1)->update([
            'processingrequired' => 0,
            'processingsuccessful' => 0,
            'processingfailreason' => ChatMessage::PROCESSFAIL_NOT_CONFIRMED,
        ]);
    }

    /**
     * Process a single pending message.
     *
     * @return bool True if the message was processed (success or failure), false if skipped.
     */
    private function processMessage(object $message): bool
    {
        $id = $message->id;
        $chatid = $message->chatid;
        $userid = $message->userid;
        $chattype = $message->chattype;
        $platform = (bool) $message->platform;

        // A ban is a fact about the sender's standing with the communities they share with
        // the person they are writing to - see isBannedInCommonGroups below, which is the
        // check that applies. It deliberately does NOT look at which communities the post
        // reached: rippling puts a post on communities the poster never chose, so asking
        // "is the sender banned anywhere this post landed?" threw away replies from members
        // in good standing wherever they and the poster actually talk (one live example was
        // 410m away, on the poster's own community, banned only on a community the post had
        // rippled into and that neither of them was conversing on).

        $review = 0;
        $reviewreason = null;
        $spam = 0;

        // Someone on the spammer list reaches nobody, whatever kind of chat this is.
        // Their mail is already dropped on the way in, volunteers address included
        // (IncomingMailService::isKnownSpammer), but the Contact button on a group page
        // opens a chat with the volunteers, and this check used to sit inside the
        // User2User branch below, so that route stayed open (Discourse 10149).
        //
        // A ban is deliberately NOT treated this way: the banned-in-common check stays
        // below, for member-to-member chats only, so a banned member can still write to
        // the volunteers to appeal. That is Edward's decision on the same thread.
        //
        // Same reasoning for a PendingAdd on the volunteers route: that is a proposal
        // awaiting a second moderator, not the list, and writing to the volunteers is how
        // someone argues they should not be added. Member-to-member keeps both
        // collections, exactly as before.
        $spamCollections = $chattype === ChatRoom::TYPE_USER2USER
            ? ['Spammer', 'PendingAdd']
            : ['Spammer'];

        $isSpammer = DB::table('spam_users')
            ->where('userid', $userid)
            ->whereIn('collection', $spamCollections)
            ->exists();

        if ($isSpammer) {
            $this->processFailed($id, ChatMessage::PROCESSFAIL_SPAMMER);

            return true;
        }

        // --- User2User review and ban checks ---
        if ($chattype === ChatRoom::TYPE_USER2USER) {
            // Check if sender is banned on all common groups with the other user.
            $otherId = $message->user1 == $userid ? $message->user2 : $message->user1;

            $bannedInCommon = $this->isBannedInCommonGroups($userid, $otherId);

            if ($bannedInCommon) {
                $this->processFailed($id, ChatMessage::PROCESSFAIL_BANNED_IN_COMMON);
                return true;
            }

            // Check if sender's messages should be held for review.
            $user = DB::table('users')->where('id', $userid)->first();
            $chatmodstatus = $user?->chatmodstatus ?? 'Moderated';

            // A Freegle-wide concern keyword whose action is 'block' drops the
            // message outright: reviewrequired = 0, reviewrejected = 1, the shape a
            // moderator's Reject writes. It is never delivered, never emailed, and
            // never enters the Chat Review queue - a wave of scam mail must not
            // land thousands of items on the volunteers. V1 (ChatMessage::process,
            // Spam::checkSpam) did the same, and let a spam keyword beat 'Fully'
            // moderation, so this runs before the hold decision for every checked
            // sender. Unmoderated senders skip content checks altogether, as in V1.
            if ($chatmodstatus !== 'Unmoderated' && $this->isContentCheckable($message->type)) {
                $blockHit = $this->contentCheck->checkBlockKeywords('', (string) ($message->message ?? ''));
                if ($blockHit !== null) {
                    $this->dropBlocked($message, $blockHit);

                    return true;
                }
            }

            if ($chatmodstatus === 'Fully') {
                // Fully moderated: every message goes to review (shadow ban).
                $review = 1;
                $reviewreason = self::REVIEW_SPAM;
            } elseif ($chatmodstatus === 'Moderated' && $this->isContentCheckable($message->type)) {
                // V1 parity: ChatMessage::process() ran Spam::checkReview() on
                // Moderated members' user-text messages and held any that matched
                // a concern keyword / link / phone number etc. That scan was lost
                // when chat_process.php was migrated to this service, so restore it.
                // Map the specific check that fired to its reportreason enum
                // value, so the review UI tells the moderator WHY (e.g. "It looks
                // like it refers to money.") rather than "...no more information
                // about why". Unmapped checks fall back to the generic 'Spam'.
                $checkResult = $this->contentCheck->checkChatMessage((string) ($message->message ?? ''));
                if ($checkResult !== null) {
                    $review = 1;
                    $reviewreason = $this->reportReasonForCheck($checkResult);
                }
            }

            // If the PREVIOUS message in this chat is held for review, hold this
            // one too. Use id < $id, not id != $id: V1's chat_process.php was a
            // continuous daemon that processed each message as it arrived, so the
            // newest other row WAS the previous one. This service processes in
            // batches (processIncoming orders by id asc), so when a burst of
            // messages is pending, "newest other row" is a LATER, not-yet-processed
            // message (reviewrequired defaults to 0) and the hold chain silently
            // breaks — subsequent messages from a member already under review get
            // delivered (Discourse #9656). Looking strictly backwards at the
            // immediately preceding (already-processed) message restores the chain.
            //
            // The question Freegle asks about a reply (chat_prompts kind confirm_reply) is written
            // straight after the reply, so it sits between a held reply and the member's next
            // message. It is never held, so it would stand in for the message before it and let the
            // next message through ahead of the held reply, in the arm that is asked only. It is
            // skipped here.
            if (!$review) {
                $lastReview = DB::table('chat_messages')
                    ->where('chatid', $chatid)
                    ->where('id', '<', $id)
                    ->whereNotExists(function ($question) {
                        $question->select('chat_prompts.chatmsgid')
                            ->from('chat_prompts')
                            ->whereColumn('chat_prompts.chatmsgid', 'chat_messages.id')
                            ->where('chat_prompts.kind', 'confirm_reply');
                    })
                    ->orderByDesc('id')
                    ->value('reviewrequired');

                if ($lastReview) {
                    $review = 1;
                    $reviewreason = self::REVIEW_LAST;
                }
            }
        }

        // Mark the message as processed.
        DB::table('chat_messages')
            ->where('id', $id)
            ->update([
                'reviewrequired' => $review,
                'reportreason' => $reviewreason,
                'reviewrejected' => $spam,
                'processingrequired' => 0,
                'processingsuccessful' => 1,
            ]);

        // Update the sender's roster position.
        $this->updateSenderRoster($id, $chatid, $userid, $platform);

        // Reopen any CLOSED roster entries for this chat (not BLOCKED).
        DB::table('chat_roster')
            ->where('chatid', $chatid)
            ->where('status', ChatRoster::STATUS_CLOSED)
            ->update(['status' => ChatRoster::STATUS_OFFLINE]);

        // V1 parity: ChatMessage::process() called notifyMembers() here when the
        // message wasn't held/banned (the spam/ban paths above early-return).
        // Hand off to a background task so we don't block this cron on FCM round-trips.
        if (!$review) {
            BackgroundTask::create([
                'task_type' => BackgroundTask::TASK_PUSH_NOTIFY_CHAT_MESSAGE,
                'data' => ['message_id' => $id],
                'created_at' => now(),
                'attempts' => 0,
            ]);

            // Surface the room in recipients' chat list now the message is deliverable.
            // ListForUser filters chat_rooms.latestmessage >= now - CHAT_ACTIVE_LIMIT (31d),
            // and neither CreateChatMessage nor this service bumped latestmessage - only the
            // hourly chats:update-counts recompute did. So a reply landing in a room dormant
            // for >31 days (Freegle keeps one User2User room per pair, reused across items)
            // stayed out of the recipient's list until that hourly cron ran, even though the
            // notification email fired immediately - up to ~1h of "email arrived but the chat
            // isn't showing". Bump here, gated on the same deliverability the email/in-app
            // fetch use (not held for review, not spam), so list visibility tracks delivery.
            // GREATEST guards against moving latestmessage backwards when a burst is processed
            // out of natural order; the hourly recompute remains as a backfill.
            // keep-raw: GREATEST(COALESCE(latestmessage, ?), ?) - both GREATEST() and
            // COALESCE() are SQL functions composed over the latestmessage column as the
            // UPDATE SET value; the query builder has no method for either, and wrapping
            // them in DB::raw() as the update() value would only relocate the raw SQL
            // into the array, not convert it.
            DB::update(
                'UPDATE chat_rooms SET latestmessage = GREATEST(COALESCE(latestmessage, ?), ?) WHERE id = ?',
                [$message->date, $message->date, $chatid]
            );
        }

        return true;
    }

    /**
     * Whether a chat message type carries member-entered text that should be
     * content checked. Mirrors the V1 ChatMessage::process() type filter; system
     * and templated messages (System, Promised, Nudge, etc.) are excluded.
     */
    private function isContentCheckable(?string $type): bool
    {
        return in_array($type, [
            ChatMessage::TYPE_DEFAULT,
            ChatMessage::TYPE_INTERESTED,
            ChatMessage::TYPE_REPORTEDUSER,
            ChatMessage::TYPE_ADDRESS,
        ], true);
    }

    /**
     * Mark a message as failed processing.
     *
     * A failed message is never notified to the recipient (ChatNotificationService only
     * takes processingsuccessful = 1), so record WHY. Without this a suppressed reply is
     * indistinguishable from one that was never sent, which is how a batch of dropped
     * replies got misdiagnosed as a rippling delay.
     */
    private function processFailed(int $messageId, ?string $reason = null): void
    {
        DB::table('chat_messages')
            ->where('id', $messageId)
            ->update([
                'processingrequired' => 0,
                'processingsuccessful' => 0,
                'processingfailreason' => $reason,
            ]);

        Log::info('ChatProcess: message suppressed', [
            'chatmsgid' => $messageId,
            'reason' => $reason,
        ]);
    }

    /**
     * Drop a message that matched a block keyword.
     *
     * Same row shape as a moderator's Reject in the Go API (chat/chatmessage.go
     * rejectChatMessage): reviewrequired = 0, reviewrejected = 1; reviewedby stays
     * NULL because nobody reviewed it. The reportreason is kept so a moderator
     * opening the chat can see why. Processing is complete (processingsuccessful
     * = 1) - the row is not a failure, it is a decision - but nothing that follows
     * delivery happens: no push task, no chat_rooms.latestmessage bump, no
     * reopening of closed rosters. The sender's own roster still moves, exactly as
     * for any message they wrote. Because reviewrequired is 0, the hold chain that
     * follows a held predecessor does not count a dropped message, as in V1.
     */
    private function dropBlocked(object $message, array $hit): void
    {
        DB::table('chat_messages')
            ->where('id', $message->id)
            ->update([
                'reviewrequired' => 0,
                'reviewrejected' => 1,
                'reportreason' => $this->reportReasonForCheck($hit),
                'processingrequired' => 0,
                'processingsuccessful' => 1,
            ]);

        $this->updateSenderRoster($message->id, $message->chatid, $message->userid, (bool) $message->platform);

        $this->dropped++;

        Log::info('ChatProcess: message dropped by block keyword', [
            'chatmsgid' => $message->id,
            'userid' => $message->userid,
            'keyword' => $hit['keyword'] ?? null,
        ]);
    }

    /**
     * Set a chat lockdown_holds row's outcome, and stamp releasedat so
     * ChatNotificationService can re-admit a message whose chat_messages.date has since
     * aged out of its look-back window by the time this fires - the same problem, and the
     * same fix, as rippling_held_replies.releasedat (ChatNotificationService::getUnmailedMessages).
     */
    private function recordHoldOutcome(int $refId, string $outcome): void
    {
        DB::table('lockdown_holds')
            ->where('kind', self::HOLD_KIND)
            ->where('refid', $refId)
            ->update(['outcome' => $outcome, 'releasedat' => now()]);
    }

    /**
     * Check if $userId is banned on all groups they have in common with $otherId.
     */
    private function isBannedInCommonGroups(int $userId, int $otherId): bool
    {
        // Get groups both users are members of.
        $commonGroups = DB::table('memberships as m1')
            ->join('memberships as m2', function ($join) use ($otherId) {
                $join->on('m1.groupid', '=', 'm2.groupid')
                    ->where('m2.userid', '=', $otherId);
            })
            ->where('m1.userid', $userId)
            ->pluck('m1.groupid');

        if ($commonGroups->isEmpty()) {
            return false;
        }

        // Check if $userId is banned on ALL common groups.
        $bannedCount = DB::table('users_banned')
            ->where('userid', $userId)
            ->whereIn('groupid', $commonGroups)
            ->count();

        return $bannedCount >= $commonGroups->count();
    }

    /**
     * Update the sender's roster entry after they sent a message.
     *
     * V1: If the message came by email (!platform), mark it seen/emailed (since the sender
     * wrote it, they've "seen" it). For platform messages, same unless they have email-mine on.
     */
    private function updateSenderRoster(int $messageId, int $chatid, int $userid, bool $platform): void
    {
        if (!$platform) {
            // Incoming email reply: only update if there are no unseen messages from the other user.
            $hasUnseen = DB::table('chat_messages as cm')
                ->leftJoin('chat_roster as cr', function ($join) use ($userid) {
                    $join->on('cr.chatid', '=', 'cm.chatid')
                        ->where('cr.userid', '=', $userid);
                })
                ->where('cm.chatid', $chatid)
                ->where('cm.userid', '!=', $userid)
                ->where('cm.seenbyall', 0)
                ->where('cm.mailedtoall', 0)
                ->where(function ($q) {
                    $q->whereNull('cr.lastmsgseen')
                        ->orWhereColumn('cr.lastmsgseen', '<', 'cm.id');
                })
                ->where(function ($q) {
                    $q->whereNull('cr.lastmsgemailed')
                        ->orWhereColumn('cr.lastmsgemailed', '<', 'cm.id');
                })
                ->exists();

            if ($hasUnseen) {
                return;
            }
        }

        DB::table('chat_roster')
            ->where('chatid', $chatid)
            ->where('userid', $userid)
            ->where(function ($q) use ($messageId) {
                $q->whereNull('lastmsgseen')
                    ->orWhere('lastmsgseen', '<', $messageId);
            })
            ->update([
                'lastmsgseen' => $messageId,
                'lastmsgemailed' => $messageId,
            ]);
    }
}
