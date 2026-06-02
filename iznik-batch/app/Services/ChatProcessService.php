<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Services\ContentCheckService;
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

    private ContentCheckService $contentCheck;

    public function __construct(?ContentCheckService $contentCheck = null)
    {
        // Resolve from the container when not injected (keeps `new ChatProcessService()` working).
        $this->contentCheck = $contentCheck ?? app(ContentCheckService::class);
    }

    /**
     * Process all pending chat messages (processingrequired = 1).
     *
     * @return int Number of messages processed.
     */
    public function processIncoming(): int
    {
        $messages = DB::table('chat_messages')
            ->join('chat_rooms', 'chat_messages.chatid', '=', 'chat_rooms.id')
            ->where('chat_messages.processingrequired', 1)
            ->orderBy('chat_messages.id', 'asc')
            ->select('chat_messages.*', 'chat_rooms.chattype', 'chat_rooms.user1', 'chat_rooms.user2')
            ->get();

        $count = 0;

        foreach ($messages as $message) {
            if ($this->processMessage($message)) {
                $count++;
            }
        }

        Log::info("ChatProcess: processed {$count} messages");

        return $count;
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

        // --- Ban check for messages with a refmsgid ---
        if (!empty($message->refmsgid)) {
            $banned = DB::table('messages_groups')
                ->join('users_banned', function ($join) use ($userid) {
                    $join->on('messages_groups.groupid', '=', 'users_banned.groupid')
                        ->where('users_banned.userid', '=', $userid);
                })
                ->where('messages_groups.msgid', $message->refmsgid)
                ->exists();

            if ($banned) {
                $this->processFailed($id);
                return true;
            }
        }

        // --- User2User spam and review checks ---
        $review = 0;
        $reviewreason = null;
        $spam = 0;

        if ($chattype === ChatRoom::TYPE_USER2USER) {
            // Check if sender is a confirmed or pending spammer.
            $isSpammer = DB::table('spam_users')
                ->where('userid', $userid)
                ->whereIn('collection', ['Spammer', 'PendingAdd'])
                ->exists();

            if ($isSpammer) {
                $this->processFailed($id);
                return true;
            }

            // Check if sender is banned on all common groups with the other user.
            $otherId = $message->user1 == $userid ? $message->user2 : $message->user1;

            $bannedInCommon = $this->isBannedInCommonGroups($userid, $otherId);

            if ($bannedInCommon) {
                $this->processFailed($id);
                return true;
            }

            // Check if sender's messages should be held for review.
            $user = DB::table('users')->where('id', $userid)->first();
            $chatmodstatus = $user?->chatmodstatus ?? 'Moderated';

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

            // If the previous message in this chat is held for review, hold this one too.
            if (!$review) {
                $lastReview = DB::table('chat_messages')
                    ->where('chatid', $chatid)
                    ->where('id', '!=', $id)
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
     */
    private function processFailed(int $messageId): void
    {
        DB::table('chat_messages')
            ->where('id', $messageId)
            ->update([
                'processingrequired' => 0,
                'processingsuccessful' => 0,
            ]);
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
