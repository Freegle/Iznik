<?php

namespace App\Services;

use App\Mail\Chat\ChatNotification;
use App\Mail\Traits\FeatureFlags;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ChatNotificationService
{
    use FeatureFlags;

    /**
     * Email type identifier for feature flag checking (User2User chats).
     */
    private const EMAIL_TYPE = 'ChatNotification';

    /**
     * Email type identifier for User2Mod chats (separate flag for gradual rollout).
     */
    private const EMAIL_TYPE_USER2MOD = 'ChatNotificationUser2Mod';

    /**
     * Email type identifier for Mod2Mod chats (separate flag for gradual rollout).
     */
    private const EMAIL_TYPE_MOD2MOD = 'ChatNotificationMod2Mod';

    /**
     * Default delay in seconds before notifying about a message.
     * This allows users to type multiple messages before notification.
     */
    public const DEFAULT_DELAY = 30;

    /**
     * How far back to look for unmailed messages.
     */
    public const DEFAULT_SINCE_HOURS = 24;

    /**
     * Optional spooler for deferred email sending.
     */
    protected ?EmailSpoolerService $spooler = null;

    /**
     * Set the spooler for deferred email sending.
     */
    public function setSpooler(EmailSpoolerService $spooler): void
    {
        $this->spooler = $spooler;
    }

    /**
     * Send notifications for a specific chat type.
     * Simplified: sends each message individually (no batching).
     */
    public function notifyByEmail(
        string $chatType,
        ?int $chatId = null,
        int $delay = self::DEFAULT_DELAY,
        int $sinceHours = self::DEFAULT_SINCE_HOURS,
        bool $forceAll = false,
        bool $dryRun = false
    ): int {
        // Check if ChatNotification emails are enabled.
        if (! self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            Log::info("ChatNotification emails are not enabled. Set FREEGLE_MAIL_ENABLED_TYPES to include 'ChatNotification'.");

            return 0;
        }

        // User2Mod notifications have a separate feature flag for gradual rollout.
        if ($chatType === ChatRoom::TYPE_USER2MOD && ! self::isEmailTypeEnabled(self::EMAIL_TYPE_USER2MOD)) {
            Log::info("ChatNotificationUser2Mod emails are not enabled. Set FREEGLE_MAIL_ENABLED_TYPES to include 'ChatNotificationUser2Mod'.");

            return 0;
        }

        // Mod2Mod notifications have a separate feature flag for gradual rollout.
        if ($chatType === ChatRoom::TYPE_MOD2MOD && ! self::isEmailTypeEnabled(self::EMAIL_TYPE_MOD2MOD)) {
            Log::info("ChatNotificationMod2Mod emails are not enabled. Set FREEGLE_MAIL_ENABLED_TYPES to include 'ChatNotificationMod2Mod'.");

            return 0;
        }

        $notified = 0;

        // Get unmailed messages that need notification.
        $messages = $this->getUnmailedMessages(
            $chatType,
            $chatId,
            $delay,
            $sinceHours,
            $forceAll
        );

        foreach ($messages as $message) {
            try {
                $notified += $this->processMessage($message, $chatType, $forceAll, $dryRun);
            } catch (\Exception $e) {
                Log::error("Error processing chat message {$message->id}: ".$e->getMessage());
            }
        }

        return $notified;
    }

    /**
     * Get unmailed messages that need notification.
     */
    protected function getUnmailedMessages(
        string $chatType,
        ?int $chatId,
        int $delay,
        int $sinceHours,
        bool $forceAll
    ): Collection {
        $startTime = now()->subHours($sinceHours);
        $endTime = now()->subSeconds($delay);

        $query = ChatMessage::query()
            ->join('chat_rooms', 'chat_messages.chatid', '=', 'chat_rooms.id')
            ->join('users', 'chat_messages.userid', '=', 'users.id')
            ->where('chat_rooms.chattype', $chatType)
            ->where('chat_messages.date', '>=', $startTime)
            ->where('chat_messages.date', '<=', $endTime)
            ->whereNull('users.deleted')
            ->select('chat_messages.*');

        // For User2User chats, only include reviewed messages.
        if ($chatType === ChatRoom::TYPE_USER2USER) {
            $query->where('chat_messages.reviewrequired', 0)
                ->where('chat_messages.processingrequired', 0)
                ->where('chat_messages.processingsuccessful', 1);
        }

        if (! $forceAll) {
            $query->where('chat_messages.mailedtoall', 0)
                ->where('chat_messages.seenbyall', 0)
                ->where('chat_messages.reviewrejected', 0);
        }

        if ($chatId) {
            $query->where('chat_rooms.id', $chatId);
        }

        return $query->orderBy('chat_messages.id', 'asc')
            ->with(['chatRoom', 'user', 'refMessage'])
            ->get();
    }

    /**
     * Process a single message and send notifications to relevant users.
     */
    protected function processMessage(ChatMessage $message, string $chatType, bool $forceAll, bool $dryRun = false): int
    {
        $notified = 0;
        $chatRoom = $message->chatRoom;

        if (! $chatRoom) {
            return 0;
        }

        // Get members who need to be notified about this message.
        $membersToNotify = $this->getMembersToNotify($chatRoom, $message, $forceAll);

        foreach ($membersToNotify as $roster) {
            try {
                $sendingTo = $roster->user;
                if (! $sendingTo || ! $sendingTo->email_preferred) {
                    continue;
                }

                // Check if we should notify this user about this message.
                if (! $this->shouldNotifyUser($sendingTo, $message, $chatRoom, $chatType, $roster->isModerator ?? false)) {
                    continue;
                }

                // Get the sender in the conversation.
                // For Mod2Mod, the sender is always the message author.
                // For User2User/User2Mod:
                //   - If this is a copy-to-self (recipient is the message author), use the message author.
                //   - Otherwise, use the "other" user in the chat.
                if ($chatType === ChatRoom::TYPE_MOD2MOD) {
                    $sendingFrom = $message->user;
                } elseif ($message->userid === $sendingTo->id) {
                    // Copy-to-self: recipient is the message author, so sender should be themselves.
                    $sendingFrom = $message->user;
                } else {
                    $sendingFrom = $this->getOtherUser($chatRoom, $sendingTo);
                }

                if ($dryRun) {
                    Log::info('Dry run: would send chat notification', [
                        'chat_id' => $chatRoom->id,
                        'message_id' => $message->id,
                        'to_user' => $sendingTo->id,
                    ]);
                } else {
                    // Send the notification email.
                    $this->sendNotificationEmail(
                        $sendingTo,
                        $sendingFrom,
                        $chatRoom,
                        $message,
                        $chatType
                    );

                    // Update roster with last message emailed and notified.
                    // lastmsgnotified is used by V1's push notification cron (notification_chaseup.php)
                    // to avoid re-notifying users for messages already handled by email.
                    $roster->update([
                        'lastmsgemailed' => $message->id,
                        'lastmsgnotified' => $message->id,
                    ]);

                    // Update message mailedtoall if all members have been notified.
                    $this->updateMailedToAll($message);
                }

                $notified++;

                Log::info('Sent chat notification', [
                    'chat_id' => $chatRoom->id,
                    'message_id' => $message->id,
                    'to_user' => $sendingTo->id,
                    'from_user' => $sendingFrom?->id,
                ]);
            } catch (\Exception $e) {
                Log::error("Error notifying user {$roster->userid} for message {$message->id}: ".$e->getMessage());
            }
        }

        return $notified;
    }

    /**
     * Get members who need to be notified about a specific message.
     *
     * For User2User chats: both users are in the roster, we notify those who haven't been mailed.
     * For User2Mod chats: we notify the member (user1) from roster, AND all group moderators.
     * For Mod2Mod chats: all mods are in the roster, we notify those who haven't been mailed.
     *
     * Users who have blocked the chat (status = 'Blocked') are excluded from notifications.
     */
    protected function getMembersToNotify(ChatRoom $chatRoom, ChatMessage $message, bool $forceAll): Collection
    {
        $results = collect();

        // Mod2Mod: All mods in the group chat are in the roster.
        if ($chatRoom->chattype === ChatRoom::TYPE_MOD2MOD && $chatRoom->groupid) {
            $query = ChatRoster::where('chatid', $chatRoom->id)
                ->notBlocked()
                ->with('user');

            if (! $forceAll) {
                $query->where(function ($q) use ($message) {
                    $q->whereNull('lastmsgemailed')
                        ->orWhere('lastmsgemailed', '<', $message->id);
                });
            }

            return $query->get()->map(function ($roster) {
                $roster->isModerator = true;

                return $roster;
            });
        }

        if ($chatRoom->chattype === ChatRoom::TYPE_USER2MOD && $chatRoom->groupid) {
            // User2Mod: Get member from roster.
            $memberRoster = ChatRoster::where('chatid', $chatRoom->id)
                ->where('userid', $chatRoom->user1)
                ->notBlocked()
                ->with('user')
                ->first();

            if ($memberRoster) {
                if ($forceAll || is_null($memberRoster->lastmsgemailed) || $memberRoster->lastmsgemailed < $message->id) {
                    $memberRoster->isModerator = false;
                    $results->push($memberRoster);
                }
            }

            // User2Mod: Get active group moderators (not backup mods).
            // Backup mods have settings['active'] = false and shouldn't receive notifications.
            $group = $chatRoom->group;
            if ($group) {
                $moderators = $group->memberships()
                    ->activeModerators()
                    ->get();

                foreach ($moderators as $membership) {
                    // Ensure mod is in roster (so we can track what we've mailed).
                    $roster = ChatRoster::firstOrCreate(
                        ['chatid' => $chatRoom->id, 'userid' => $membership->userid],
                        ['lastmsgseen' => null, 'lastmsgemailed' => null]
                    );

                    // Load the user relationship.
                    $roster->load('user');

                    // Skip mods who have closed or blocked this chat — V1 PHP also
                    // skips these via the status filter in unseenCountForUser().
                    if (in_array($roster->status, [ChatRoster::STATUS_CLOSED, ChatRoster::STATUS_BLOCKED])) {
                        continue;
                    }

                    // Check if we need to notify this moderator.
                    if ($forceAll || is_null($roster->lastmsgemailed) || $roster->lastmsgemailed < $message->id) {
                        $roster->isModerator = true;
                        $results->push($roster);
                    }
                }
            }

            return $results->unique('userid');
        }

        // User2User: Use standard roster-based logic.
        // Only notify the actual chat participants (user1/user2), not mods who may have
        // added mod notes to the chat. See original iznik-server getMembersStatus().
        $query = ChatRoster::where('chatid', $chatRoom->id)
            ->whereIn('userid', [$chatRoom->user1, $chatRoom->user2])
            ->notBlocked()
            ->with('user');

        if (! $forceAll) {
            // Only get members who haven't been mailed this message yet.
            $query->where(function ($q) use ($message) {
                $q->whereNull('lastmsgemailed')
                    ->orWhere('lastmsgemailed', '<', $message->id);
            });
        }

        return $query->get()->map(function ($roster) {
            $roster->isModerator = false;

            return $roster;
        });
    }

    /**
     * Check if a user should be notified about a specific message.
     *
     * @param  User  $user  The user to potentially notify
     * @param  ChatMessage  $message  The message to notify about
     * @param  ChatRoom  $chatRoom  The chat room
     * @param  string  $chatType  The chat type (User2User, User2Mod, etc.)
     * @param  bool  $isModerator  Whether this user is a moderator in this chat context
     */
    protected function shouldNotifyUser(User $user, ChatMessage $message, ChatRoom $chatRoom, string $chatType, bool $isModerator = false): bool
    {
        // Check if this is the user's own message.
        $isOwnMessage = $message->userid === $user->id;

        if ($isOwnMessage) {
            // Only send copy of own messages if user has this preference enabled.
            if (! $user->notifsOn(User::NOTIFS_EMAIL_MINE)) {
                return false;
            }
        }

        // For User2Mod chats:
        // - Always notify the member (user1)
        // - Notify moderators based on their notification preferences
        if ($chatType === ChatRoom::TYPE_USER2MOD) {
            if ($chatRoom->user1 === $user->id) {
                // Always notify the member.
                return true;
            }

            if ($isModerator) {
                // Notify moderator based on their email notification preferences.
                // Mods might have notifications off, in which case we don't bother them.
                return $user->notifsOn(User::NOTIFS_EMAIL, $chatRoom->groupid);
            }
        }

        // For Mod2Mod chats:
        // - All participants are moderators
        // - Notify based on their email notification preferences for the group
        if ($chatType === ChatRoom::TYPE_MOD2MOD) {
            return $user->notifsOn(User::NOTIFS_EMAIL, $chatRoom->groupid);
        }

        // TN users always get notifications.
        if ($user->isTN()) {
            return true;
        }

        // Check user's notification preferences.
        return $user->notifsOn(User::NOTIFS_EMAIL, $chatRoom->groupid);
    }

    /**
     * Get the other user in a chat room.
     */
    protected function getOtherUser(ChatRoom $chatRoom, User $currentUser): ?User
    {
        if ($chatRoom->user1 === $currentUser->id) {
            return User::find($chatRoom->user2);
        }

        return User::find($chatRoom->user1);
    }

    /**
     * Get previous messages for context (up to 3 messages before the current one).
     */
    protected function getPreviousMessages(
        ChatRoom $chatRoom,
        ChatMessage $currentMessage,
        int $limit = 3
    ): Collection {
        return ChatMessage::where('chatid', $chatRoom->id)
            ->where('id', '<', $currentMessage->id)
            ->where('date', '>=', now()->subDays(90))
            ->where('reviewrejected', 0)
            ->whereHas('user', function ($q) {
                $q->whereNull('deleted');
            })
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->with(['user', 'refMessage'])
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Send a notification email to a user.
     */
    protected function sendNotificationEmail(
        User $sendingTo,
        ?User $sendingFrom,
        ChatRoom $chatRoom,
        ChatMessage $message,
        string $chatType
    ): void {
        // Get previous messages for context.
        $previousMessages = $this->getPreviousMessages($chatRoom, $message);

        $mailable = new ChatNotification(
            $sendingTo,
            $sendingFrom,
            $chatRoom,
            $message,
            $chatType,
            $previousMessages
        );

        if ($this->spooler) {
            $this->spooler->spool($mailable, $sendingTo->email_preferred, 'chat');
        } else {
            Mail::send($mailable);
        }
    }

    /**
     * Update the mailedtoall flag if all roster members have been notified.
     */
    protected function updateMailedToAll(ChatMessage $message): void
    {
        // Check if all roster members have been mailed this message.
        $notMailedCount = ChatRoster::where('chatid', $message->chatid)
            ->where(function ($q) use ($message) {
                $q->whereNull('lastmsgemailed')
                    ->orWhere('lastmsgemailed', '<', $message->id);
            })
            ->count();

        if ($notMailedCount === 0) {
            $message->update(['mailedtoall' => 1]);
        }
    }
}
