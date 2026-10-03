<?php

namespace App\Mail\Chat;

use App\Mail\Contracts\RetryableMailable;
use App\Mail\MjmlMailable;
use App\Mail\Traits\AmpEmail;
use App\Mail\Traits\AvatarResolver;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\DonateLinkService;
use App\Services\UnsubscribeService;
use App\Support\AmpEmailSupport;
use App\Support\EmojiUtils;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Symfony\Component\Mime\Email;

class ChatNotification extends MjmlMailable implements RetryableMailable
{
    use AmpEmail;
    use AvatarResolver;
    use LoggableEmail;
    use TrackableEmail;

    public User $recipient;

    public ?User $sender;

    public ChatRoom $chatRoom;

    public ChatMessage $message;

    public string $chatType;

    public string $userSite;

    public string $deliveryUrl;

    public string $chatUrl;

    public string $replySubject;

    public Collection $previousMessages;

    public ?Message $refMessage;

    /**
     * Whether this is a "waiting for reply" chase-up of an expected reply.
     * When TRUE the subject is prefixed with "WAITING FOR REPLY: " to match
     * the legacy V1 PHP ChatRoom::chaseupExpected() behaviour.
     */
    public bool $waitingForReply = false;

    /**
     * Whether this notification is for the sender's own message (copy to self).
     */
    public bool $isOwnMessage;

    /**
     * Whether the recipient is a moderator (for User2Mod chats).
     * Moderators see different subject lines, URLs, and styling.
     */
    public bool $isModerator = false;

    /**
     * The member in a User2Mod chat (the non-moderator user).
     * Used for moderator notifications to show member info in subject.
     */
    public ?User $member = null;

    /**
     * The ModTools site URL.
     */
    public string $modSite;

    protected string $replyToAddress;

    protected string $fromDisplayName;

    protected string $userDomain;

    /**
     * Create a new message instance.
     */
    public function __construct(
        User $recipient,
        ?User $sender,
        ChatRoom $chatRoom,
        ChatMessage $message,
        string $chatType,
        ?Collection $previousMessages = null,
        bool $waitingForReply = false
    ) {
        $this->recipient = $recipient;
        $this->sender = $sender;
        $this->chatRoom = $chatRoom;
        $this->message = $message;
        $this->chatType = $chatType;
        $this->waitingForReply = $waitingForReply;
        $this->previousMessages = $previousMessages ?? collect();
        $this->userSite = config('freegle.sites.user');
        $this->modSite = config('freegle.sites.mod');
        $this->deliveryUrl = config('freegle.delivery.base_url');
        $this->userDomain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');

        // Get referenced message from the chat message.
        $this->refMessage = $message->refMessage;

        // Check if this is a copy of the recipient's own message.
        // This happens when a user sends a message and has opted to receive copies of their own messages.
        $this->isOwnMessage = $message->userid === $recipient->id;

        // For User2Mod chats, determine if recipient is a moderator or the member.
        // user1 in the chat is always the member; anyone else in a User2Mod room is a moderator.
        if ($chatType === ChatRoom::TYPE_USER2MOD) {
            $this->member = User::find($chatRoom->user1);
            // Recipient is a moderator if they're NOT the member (user1)
            $this->isModerator = $recipient->id !== $chatRoom->user1;
        }

        // For Mod2Mod chats, all participants are moderators.
        if ($chatType === ChatRoom::TYPE_MOD2MOD) {
            $this->isModerator = true;
        }

        // Set chat URL based on whether recipient is a moderator.
        // Moderators use ModTools, members use the user site.
        $this->chatUrl = $this->isModerator
            ? $this->modSite.'/chats/'.$chatRoom->id
            : $this->userSite.'/chats/'.$chatRoom->id;

        // Build the subject line.
        $this->replySubject = $this->generateSubject();

        // Build reply-to address for chat routing.
        // Format: notify-{chatid}-{userid}@{domain}
        $this->replyToAddress = 'notify-'.$chatRoom->id.'-'.$recipient->id.'@'.$this->userDomain;

        // Build from display name based on chat type. There is one national mod pool,
        // so User2Mod/Mod2Mod display names use the site name rather than any group:
        // - Member receives: "Freegle Volunteers" (hide mod identity)
        // - Mod receives from member: "{MemberName} via Freegle"
        // - Mod receives from another mod: "Freegle Volunteers"
        $siteName = config('freegle.branding.name', 'Freegle');

        if ($chatType === ChatRoom::TYPE_USER2MOD) {
            if ($this->isModerator) {
                // Moderator receiving - check if message is from member or another mod.
                if ($sender && $sender->id === $chatRoom->user1) {
                    // Message from member - show member's name.
                    $senderName = $sender->displayname ?? 'A member';
                    $this->fromDisplayName = $senderName.' via '.$siteName;
                } else {
                    // Message from another mod - use the volunteers identity.
                    $this->fromDisplayName = $siteName.' Volunteers';
                }
            } else {
                // Member receiving - always show the volunteers identity, hide mod identity.
                $this->fromDisplayName = $siteName.' Volunteers';
            }
        } elseif ($chatType === ChatRoom::TYPE_MOD2MOD) {
            // Mod2Mod - show sender name, mods can see each other's identities.
            $senderName = $sender?->displayname ?? 'A volunteer';
            $this->fromDisplayName = $senderName.' ('.$siteName.' Volunteers)';
        } else {
            // User2User - show sender name.
            $senderName = $sender?->displayname ?? 'Someone';
            $this->fromDisplayName = $senderName.' on '.$siteName;
        }

        // Initialize email tracking.
        // Only pass user ID if it's a persisted user (exists in database).
        $userId = $this->recipient->exists ? $this->recipient->id : null;

        // Determine if this email will include AMP content.
        // This must match the logic in build() that decides whether to render AMP.
        // Only mark has_amp=true if the recipient's email domain actually supports AMP.
        $hasAmp = $this->isAmpEnabled()
            && $this->chatType === ChatRoom::TYPE_USER2USER
            && $this->recipient->exists
            && AmpEmailSupport::isSupported($this->recipient->email_preferred);

        $this->initTracking(
            'ChatNotification',
            $this->recipient->email_preferred,
            $userId,
            null,
            $this->replySubject,
            [
                'chat_id' => $chatRoom->id,
                'sender_id' => $sender?->id,
                'message_id' => $message->id,
            ],
            $hasAmp
        );
    }

    protected function unsubscribeType(): ?string
    {
        return UnsubscribeService::TYPE_CHAT;
    }

    /**
     * Get the recipient's user ID for tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->recipient->id;
    }

    /**
     * IDs needed to rebuild this notification for a durable retry — recipient,
     * sender, chat, message, type, and the ids of the context ("earlier in
     * this conversation") messages. Never the built models.
     *
     * {@see RetryableMailable}
     */
    public function mailDescriptor(): array
    {
        return [
            'recipient' => $this->recipient->id,
            'sender' => $this->sender?->id,
            'chatid' => $this->chatRoom->id,
            'messageid' => $this->message->id,
            'chattype' => $this->chatType,
            'previous' => $this->previousMessages->pluck('id')->all(),
        ];
    }

    /**
     * Rebuild a fresh notification from a descriptor, re-fetching from the DB.
     *
     * Returns null (cancel the retry) when the recipient, chat, or triggering
     * message has since been deleted, or the recipient no longer has a usable
     * address. A missing sender is allowed (the constructor accepts a null
     * sender), as are missing context messages.
     *
     * {@see RetryableMailable}
     */
    public static function rebuildFromDescriptor(array $descriptor): ?self
    {
        $recipient = User::find($descriptor['recipient'] ?? null);
        if (! $recipient || ! $recipient->email_preferred) {
            return null;
        }

        $message = ChatMessage::find($descriptor['messageid'] ?? null);
        $chatRoom = ChatRoom::find($descriptor['chatid'] ?? null);
        if (! $message || ! $chatRoom) {
            return null;
        }

        $sender = isset($descriptor['sender']) ? User::find($descriptor['sender']) : null;

        $previous = ! empty($descriptor['previous'])
            ? ChatMessage::whereIn('id', $descriptor['previous'])->orderBy('id')->get()
            : collect();

        return new self(
            $recipient,
            $sender,
            $chatRoom,
            $message,
            $descriptor['chattype'] ?? ChatRoom::TYPE_USER2USER,
            $previous
        );
    }

    /**
     * Get the message envelope with custom from/replyTo.
     *
     * Uses noreply@ilovefreegle.org as the From address because it's whitelisted
     * for AMP email sending. The notify address is preserved as Reply-To so that
     * replies still route correctly through the chat system.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                $this->fromDisplayName
            ),
            replyTo: [new Address($this->replyToAddress, $this->fromDisplayName)],
            subject: $this->getSubject(),
        );
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        // Prepare the message with display-friendly data.
        $preparedMessage = $this->prepareMessage($this->message);
        $preparedPreviousMessages = $this->prepareMessages($this->previousMessages);

        // Check if we should show outcome buttons (for OFFER items with Interested messages).
        $showOutcomeButtons = $this->shouldShowOutcomeButtons();
        $outcomeUrls = $showOutcomeButtons ? $this->getOutcomeUrls() : [];

        // Check if reply is expected.
        $replyExpected = $this->message->replyexpected ?? false;

        // Get job ads for the recipient and add tracked URLs.
        $jobAds = $this->recipient->getJobAds();
        $jobCount = count($jobAds['jobs']);
        foreach ($jobAds['jobs'] as $index => $job) {
            $job->tracked_url = $this->trackedUrl(
                config('freegle.sites.user').'/job/'.$job->id.
                '?source=email&campaign=chat_notification&position='.$index.
                '&list_length='.$jobCount,
                'job_ad_'.$index,
                'job_click'
            );
        }

        // Check if recipient is the poster of the referenced message.
        $isRecipientPoster = $this->refMessage && $this->refMessage->fromuser === $this->recipient->id;

        // Get item image URL if there's a referenced message with attachments.
        $refMessageImageUrl = $this->getRefMessageImageUrl();

        // For User2Mod chats when notifying a member, always hide mod identity.
        // Any message notification to a member is from volunteers (even if sender is NULL).
        // We don't need to check who the sender is - members always see "Volunteers".
        $shouldHideModIdentity = $this->chatType === ChatRoom::TYPE_USER2MOD
            && ! $this->isModerator;  // Recipient is a member

        // Build sender page URL (for clicking on sender name/image).
        // Moderators should go to ModTools, members to user site.
        // For hidden mod identity there's no individual or group page to send them to,
        // so the sender name isn't a link.
        if ($shouldHideModIdentity) {
            $senderPageUrl = null;
        } elseif ($this->sender?->id) {
            $profileSite = $this->isModerator ? $this->modSite : $this->userSite;
            $senderPageUrl = $this->trackedUrl($profileSite.'/profile/'.$this->sender->id, 'sender_profile', 'profile');
        } else {
            $senderPageUrl = null;
        }

        // Get sender name and profile, hiding mod identity for members.
        $siteName = config('freegle.branding.name', 'Freegle');
        $senderName = $shouldHideModIdentity
            ? $siteName.' Volunteers'
            : ($this->sender?->displayname ?? 'Someone');
        $senderProfileUrl = $shouldHideModIdentity
            ? $this->resolveAvatarUrl(null, 40)
            : $this->getSenderProfileUrl();

        // For mod view, determine if the sender is the member or another mod.
        $senderIsMember = $this->isModerator
            && $this->sender
            && $this->sender->id === $this->chatRoom->user1;

        // Check if AMP will be included (used for footer indicator).
        // Must check domain support - AMP only works for Gmail, Yahoo, etc.
        $ampIncluded = $this->isAmpEnabled()
            && $this->chatType === ChatRoom::TYPE_USER2USER
            && $this->recipient->exists
            && AmpEmailSupport::isSupported($this->recipient->email_preferred);

        // For own message notifications, we need the other user's name.
        // The "other user" is the one in the chat who is NOT the recipient.
        $otherUserName = null;
        if ($this->isOwnMessage && $this->chatType === ChatRoom::TYPE_USER2USER) {
            $otherUserId = $this->chatRoom->user1 === $this->recipient->id
                ? $this->chatRoom->user2
                : $this->chatRoom->user1;
            $otherUser = User::find($otherUserId);
            $otherUserName = $otherUser?->displayname ?? 'the other user';
        }

        $this->to($this->recipient->email_preferred, $this->recipient->displayname)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.chat.notification', array_merge([
                'recipient' => $this->recipient,
                'recipientName' => $this->recipient->displayname,
                'sender' => $this->sender,
                'senderName' => $senderName,
                'senderProfileUrl' => $senderProfileUrl,
                'senderPageUrl' => $senderPageUrl,
                'chatRoom' => $this->chatRoom,
                'chatMessage' => $preparedMessage,
                'previousMessages' => $preparedPreviousMessages,
                'chatType' => $this->chatType,
                'chatUrl' => $this->trackedUrl($this->chatUrl, 'reply_button', 'reply'),
                'refMessage' => $this->refMessage,
                'refMessageImageUrl' => $refMessageImageUrl,
                'isRecipientPoster' => $isRecipientPoster,
                'replyExpected' => $replyExpected,
                'showOutcomeButtons' => $showOutcomeButtons,
                'outcomeUrls' => $outcomeUrls,
                'isUser2Mod' => $this->chatType === ChatRoom::TYPE_USER2MOD,
                'isMod2Mod' => $this->chatType === ChatRoom::TYPE_MOD2MOD,
                'isModerator' => $this->isModerator,
                'senderIsMember' => $senderIsMember,
                'member' => $this->member,
                'memberName' => $this->member?->displayname ?? 'the member',
                // ModTools has no member-profile route that isn't scoped to a group any
                // more, so there's nowhere group-free to send this link yet.
                'memberProfileUrl' => null,
                'groupName' => $siteName,
                'groupShortName' => $siteName,
                'settingsUrl' => $this->trackedUrl(
                    $this->isModerator ? $this->modSite.'/settings' : $this->userSite.'/settings',
                    'footer_settings',
                    'settings'
                ),
                'unsubscribeUrl' => $this->trackedUrl(
                    $this->isModerator ? $this->modSite.'/settings' : $this->userSite.'/unsubscribe',
                    'footer_unsubscribe',
                    'unsubscribe'
                ),
                'jobAds' => $jobAds['jobs'],
                'jobsUrl' => $this->trackedUrl($this->userSite.'/jobs', 'jobs_link', 'jobs'),
                // Our own Stripe donate page rather than the PayPal-only
                // shortlink, so Apple Pay / Google Pay / Link are on offer too.
                // See DonateLinkService.
                'donateUrl' => $this->trackedUrl(
                    app(DonateLinkService::class)->url(
                        $this->recipient,
                        app(DonateLinkService::class)->defaultAmount(),
                        'chatnotify'
                    ),
                    'donate_link',
                    'donate'
                ),
                'donateMarksUrl' => config('freegle.images.paymethods'),
                'ampIncluded' => $ampIncluded,
                'isOwnMessage' => $this->isOwnMessage,
                'otherUserName' => $otherUserName,
                // A Freegle prompt asks a question whose answers are buttons, and
                // buttons cannot live in email: a one-click answer in a mail is
                // answerable by anyone who ever sees that mail, including a
                // forwarded copy, and these answers change what the member's post
                // says. So the mail carries the question and sends them to the
                // chat to answer it - which the call to action has to be honest
                // about, rather than saying "Reply to Freegle".
                'isPrompt' => $this->message->type === ChatMessage::TYPE_PROMPT,
            ], $this->getTrackingData()), 'emails.text.chat.notification');

        // Render AMP version if enabled, User2User chat, and recipient's domain supports AMP.
        if ($this->isAmpEnabled()
            && $this->chatType === ChatRoom::TYPE_USER2USER
            && $this->recipient->exists
            && AmpEmailSupport::isSupported($this->recipient->email_preferred)) {
            $this->renderAmpContent();
        }

        // Add custom X-Freegle headers, threading headers, and read receipts.
        $this->withSymfonyMessage(function (Email $symfonyMessage) {
            $headers = $symfonyMessage->getHeaders();

            // Add RFC 2822 threading headers so email clients (especially Gmail)
            // correctly thread chat notifications per conversation.
            //
            // Without these, Gmail sees multiple notifications with the same subject,
            // same sender, and same structure, and collapses the body of subsequent
            // ones as "quoted text" - hiding the actual new message content.
            //
            // With proper threading headers, Gmail threads them into one conversation
            // but displays each message's body separately.
            //
            // Message-ID: Unique per chat message, deterministic from chatid + message id.
            // References: Thread anchor (stable per chat room) + previous message IDs.
            // In-Reply-To: The most recent previous message's ID.
            $messageId = "chat-{$this->chatRoom->id}-msg-{$this->message->id}@{$this->userDomain}";
            $threadAnchor = "chat-{$this->chatRoom->id}-thread@{$this->userDomain}";

            // Replace existing headers before adding (build() may be called multiple
            // times, e.g. render() then send(), which re-registers this callback).
            foreach (['Message-ID', 'References', 'In-Reply-To'] as $headerName) {
                if ($headers->has($headerName)) {
                    $headers->remove($headerName);
                }
            }

            $headers->addIdHeader('Message-ID', $messageId);

            // Build References chain: thread anchor + previous message IDs.
            $references = [$threadAnchor];
            foreach ($this->previousMessages as $prevMsg) {
                $references[] = "chat-{$this->chatRoom->id}-msg-{$prevMsg->id}@{$this->userDomain}";
            }
            $headers->addIdHeader('References', $references);

            // In-Reply-To: the most recent previous message, or the thread anchor if none.
            $lastPrevMsg = $this->previousMessages->last();
            $inReplyTo = $lastPrevMsg
                ? "chat-{$this->chatRoom->id}-msg-{$lastPrevMsg->id}@{$this->userDomain}"
                : $threadAnchor;
            $headers->addIdHeader('In-Reply-To', $inReplyTo);

            // Add mail type header for tracking.
            $headers->addTextHeader('X-Freegle-Mail-Type', 'ChatNotification');

            // List-Unsubscribe is added by MjmlMailable::addListUnsubscribeHeaders() from
            // the category this mailable declares. It used to be added here as well,
            // pointing at the one-click page that deletes the account - so the mail
            // carried two conflicting List-Unsubscribe headers, one of which answered
            // "stop emailing me about chats" by removing the member's account.

            // Add referenced message IDs if available.
            if ($this->refMessage) {
                $headers->addTextHeader('X-Freegle-Msgids', (string) $this->refMessage->id);
            }

            // Add sender user ID for User2User and Mod2Mod chats.
            if (($this->chatType === ChatRoom::TYPE_USER2USER || $this->chatType === ChatRoom::TYPE_MOD2MOD)
                && $this->sender?->id) {
                $headers->addTextHeader('X-Freegle-From-UID', (string) $this->sender->id);
            }

            // Add read receipt headers for User2User and Mod2Mod chats.
            if (($this->chatType === ChatRoom::TYPE_USER2USER || $this->chatType === ChatRoom::TYPE_MOD2MOD)
                && $this->recipient->exists) {
                $readReceiptAddr = "readreceipt-{$this->chatRoom->id}-{$this->recipient->id}-{$this->message->id}@{$this->userDomain}";
                $headers->addTextHeader('Disposition-Notification-To', $readReceiptAddr);
                $headers->addTextHeader('Return-Receipt-To', $readReceiptAddr);
            }

            // Apply AMP content if rendered.
            $this->applyAmpToMessage($symfonyMessage);
        });

        // Apply email logging if configured.
        $this->applyLogging('ChatNotification');

        return $this;
    }

    /**
     * Get the subject line for the email.
     */
    protected function getSubject(): string
    {
        return $this->replySubject;
    }

    /**
     * Generate the subject line based on context.
     *
     * Matches the legacy V1 PHP logic: use the last "interested in" message in the chat
     * to get the item subject, since that's the most likely thing they're talking about.
     *
     * For User2Mod chats:
     * - Member gets: "Your conversation with the {SiteName} Volunteers"
     * - Moderator gets: "Member conversation with {MemberName} ({email})"
     *
     * For Mod2Mod chats:
     * - All mods get: "{SiteName} Volunteer Chat: {SenderName}"
     */
    protected function generateSubject(): string
    {
        $subject = $this->generateBaseSubject();

        // Chase-up of an expected reply: prefix to match the legacy V1 PHP
        // ChatRoom::chaseupExpected() behaviour.
        if ($this->waitingForReply) {
            return 'WAITING FOR REPLY: '.$subject;
        }

        return $subject;
    }

    /**
     * Generate the base (un-prefixed) subject line based on context.
     */
    protected function generateBaseSubject(): string
    {
        if ($this->chatType === ChatRoom::TYPE_USER2MOD) {
            $siteName = config('freegle.branding.name', 'Freegle');

            if ($this->isModerator) {
                // Moderator subject. No group name - there is one national mod pool
                // rather than a group the member happened to contact.
                $memberName = $this->member?->displayname ?? 'A member';
                $memberEmail = $this->member?->email_preferred ?? '';

                return "Member conversation with {$memberName} ({$memberEmail})";
            }

            // Member subject.
            return "Your conversation with the {$siteName} Volunteers";
        }

        if ($this->chatType === ChatRoom::TYPE_MOD2MOD) {
            $siteName = config('freegle.branding.name', 'Freegle');
            $senderName = $this->sender?->displayname ?? 'A volunteer';

            return "{$siteName} Volunteer Chat: {$senderName}";
        }

        // For USER2USER chats, find the last "interested in" message to get the item subject.
        // This matches the legacy V1 PHP getChatEmailSubject() logic.
        $interestedInfo = $this->getLastInterestedMessageInfo();

        if ($interestedInfo) {
            // Format: "Regarding: ItemSubject" - deliberately NO group name. With
            // rippling a post sits on many groups and any single pick can mislead:
            // the old "most relevant" pick (recipient's memberships sorted by
            // messages_groups.arrival DESC) chose the most-recently-RIPPLED group,
            // so a Bristol post's replies arrived as [Bath Freegle], [Dursley],
            // [North Cotswolds] - changing as the poster's rippled memberships
            // changed. The item subject's own location suffix says where it is.
            // Strip any existing "Regarding:" or "Re:" prefixes from the subject.
            $subject = $interestedInfo['subject'];
            $subject = str_replace('Regarding:', '', $subject);
            $subject = str_replace('Re: ', '', $subject);
            $subject = trim($subject);

            return "Regarding: {$subject}";
        }

        // Fallback if no interested message found.
        return '[Freegle] You have a new message';
    }

    /**
     * Get the last "interested in" message info for this chat.
     *
     * This queries the chat for the most recent TYPE_INTERESTED message and returns
     * the associated item's subject.
     *
     * @return array{subject: string}|null
     */
    protected function getLastInterestedMessageInfo(): ?array
    {
        // Query for the most recent TYPE_INTERESTED message in this chat.
        $interestedMessage = ChatMessage::where('chatid', $this->chatRoom->id)
            ->where('type', ChatMessage::TYPE_INTERESTED)
            ->whereNotNull('refmsgid')
            ->orderByDesc('id')
            ->first();

        if (! $interestedMessage) {
            return null;
        }

        $refMessage = $interestedMessage->refMessage;
        if (! $refMessage) {
            return null;
        }

        return [
            'subject' => $refMessage->subject,
        ];
    }

    /**
     * Get a clean snippet of the message for use in subject lines.
     * Matches the snippet format used in the legacy V1 PHP ChatRoom::getSnippet().
     *
     * @param  int  $maxLength  Maximum length of the snippet
     */
    protected function getSubjectSnippet(int $maxLength = 40): string
    {
        $text = $this->message->message ?? '';

        // For certain message types, use generic descriptions (matching the legacy V1 PHP implementation).
        switch ($this->message->type) {
            case ChatMessage::TYPE_ADDRESS:
                return 'Address sent...';
            case ChatMessage::TYPE_NUDGE:
                return 'Nudged';
            case ChatMessage::TYPE_PROMISED:
                return 'Item promised...';
            case ChatMessage::TYPE_RENEGED:
                return 'Promise cancelled...';
            case ChatMessage::TYPE_COMPLETED:
                // Match the legacy V1 PHP implementation: different text for OFFER (TAKEN) vs WANTED (RECEIVED).
                if ($this->refMessage?->type === Message::TYPE_OFFER) {
                    if (! empty($text)) {
                        break; // Use the text below.
                    }

                    return 'Item marked as TAKEN';
                }

                return 'Item marked as RECEIVED...';
            case ChatMessage::TYPE_IMAGE:
                // If there's text with the image, use that; otherwise use generic.
                if (empty($text)) {
                    return 'Image...';
                }
                break;
            case ChatMessage::TYPE_INTERESTED:
                // If there's text, use it; otherwise use generic.
                if (empty($text)) {
                    return 'Interested...';
                }
                break;
                // For DEFAULT and MODMAIL, use the actual text.
        }

        // Decode emoji escape sequences.
        $text = EmojiUtils::decodeEmojis($text);

        // If empty after processing, return empty.
        if (empty(trim($text))) {
            return '';
        }

        // Clean up the text for a subject line (normalize whitespace, remove newlines).
        $text = preg_replace('/\s+/', ' ', trim($text));

        // Truncate with ellipsis if needed.
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength - 1).'…';
        }

        return $text;
    }

    /**
     * Prepare messages for display.
     */
    protected function prepareMessages(Collection $messages): Collection
    {
        return $messages->map(function ($message) {
            return $this->prepareMessage($message);
        });
    }

    /**
     * Prepare a single message for display.
     *
     * For User2Mod chats:
     * - When notifying a member, mod messages show "Volunteers" and a generic avatar.
     * - When notifying a mod, messages show actual user names/profiles.
     */
    protected function prepareMessage(ChatMessage $message): array
    {
        $isFromRecipient = $message->userid === $this->recipient->id;
        $messageUser = $message->user;

        // Get display text based on message type.
        // Under the warn-not-hold experiment a held message from the other person is
        // described, not quoted: the member reads the text in the app, behind the warning.
        $sensitive = null;
        if ($message->reviewrequired && ! $isFromRecipient
            && \App\Support\ChatWarnNotHold::deliverable(true, $message->reportreason)) {
            $sensitive = \App\Support\ChatWarnNotHold::reason($message->reportreason);
            $displayText = \App\Support\ChatWarnNotHold::warningText($sensitive);
        } else {
            $displayText = $this->getMessageDisplayText($message);
        }

        // Determine if this message is from a moderator (for User2Mod identity handling).
        // In User2Mod chats, user1 is always the member, anyone else is a mod.
        $isFromMod = $this->chatType === ChatRoom::TYPE_USER2MOD
            && $message->userid !== $this->chatRoom->user1;

        // For User2Mod chats when notifying a member, hide mod identity.
        // This matches the legacy V1 PHP prepareForTwig() behavior.
        $shouldHideModIdentity = $this->chatType === ChatRoom::TYPE_USER2MOD
            && ! $this->isModerator  // Recipient is a member
            && $isFromMod;          // Message is from a mod

        // Get profile image URL.
        // For mod messages to members, use the generic volunteers avatar instead of
        // the individual mod's profile - there is no group to attribute it to.
        if ($shouldHideModIdentity) {
            $profileUrl = $this->resolveAvatarUrl(null, 40);
        } else {
            $profileUrl = $this->getProfileImageUrl($messageUser);
        }

        // Get image URL if this is an image message.
        $imageUrl = $this->getMessageImageUrl($message);

        // Get referenced message info if this message refers to an item.
        $refMessageInfo = $this->getMessageRefInfo($message);

        // Get user page URL for clicking on profile.
        // Moderators should go to ModTools, members to user site.
        // For hidden mod identity there's no individual or group page to link to.
        if ($shouldHideModIdentity) {
            $userPageUrl = null;
        } elseif ($messageUser?->id) {
            $profileSite = $this->isModerator ? $this->modSite : $this->userSite;
            $userPageUrl = $this->trackedUrl($profileSite.'/profile/'.$messageUser->id, 'message_profile', 'profile');
        } else {
            $userPageUrl = null;
        }

        // Get map URL for address messages.
        $mapUrl = $this->getAddressMapUrl($message);

        // Determine the display name for the message author.
        // For mod messages to members, show "Volunteers" instead of individual name.
        $userName = $shouldHideModIdentity
            ? config('freegle.branding.name', 'Freegle').' Volunteers'
            : ($messageUser?->displayname ?? 'Someone');

        return [
            'id' => $message->id,
            'type' => $message->type,
            'text' => $displayText,
            'sensitive' => $sensitive,
            'imageUrl' => $imageUrl,
            'profileUrl' => $profileUrl,
            'userPageUrl' => $userPageUrl,
            'userName' => $userName,
            'date' => $message->date,
            // Dates are stored in UTC; chat notifications go to UK users, so render
            // in UK local time (honours BST) rather than UTC. Matches UnifiedDigest.
            'formattedDate' => $message->date?->setTimezone('Europe/London')->format('M j, g:i a') ?? '',
            'isFromRecipient' => $isFromRecipient,
            'replyExpected' => $message->replyexpected ?? false,
            'refMessage' => $refMessageInfo,
            'mapUrl' => $mapUrl,
        ];
    }

    /**
     * Get referenced message info (subject and image) for a chat message.
     */
    protected function getMessageRefInfo(ChatMessage $message): ?array
    {
        $refMsg = $message->refMessage;
        if (! $refMsg) {
            return null;
        }

        // Get the primary attachment for the referenced message.
        $attachment = $refMsg->attachments()
            ->orderByDesc('primary')
            ->first();

        $imageUrl = null;
        if ($attachment) {
            if (! empty($attachment->externalurl)) {
                $imageUrl = $this->getDeliveryUrl($attachment->externalurl, 75);
            } else {
                $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
                $imageUrl = $this->getDeliveryUrl("{$imagesDomain}/timg_{$attachment->id}.jpg", 75);
            }
        }

        // Use ModTools for moderators, user site for members.
        $messageSite = $this->isModerator ? $this->modSite : $this->userSite;

        return [
            'subject' => $refMsg->subject,
            'imageUrl' => $imageUrl,
            'url' => $this->trackedUrl($messageSite.'/message/'.$refMsg->id, 'ref_message', 'view_item'),
        ];
    }

    /**
     * Get the display name of the other user in the chat (not the recipient).
     *
     * For Promise/Reneged messages we need the actual other user in the chat,
     * not $this->sender, because in copy-to-self notifications the sender is
     * the same as the recipient which would show "You promised this to yourself".
     */
    protected function getOtherUserName(): string
    {
        if ($this->chatRoom && $this->chatType === ChatRoom::TYPE_USER2USER) {
            $otherUserId = $this->chatRoom->user1 === $this->recipient->id
                ? $this->chatRoom->user2
                : $this->chatRoom->user1;
            $otherUser = User::find($otherUserId);

            if ($otherUser) {
                return $otherUser->displayname ?? 'Someone';
            }
        }

        return $this->sender?->displayname ?? 'Someone';
    }

    /**
     * Get display text for a message based on its type.
     */
    protected function getMessageDisplayText(ChatMessage $message): string
    {
        // Decode emoji escape sequences (\u{codepoints}\u) to actual emojis.
        $text = EmojiUtils::decodeEmojis($message->message ?? '');

        switch ($message->type) {
            case ChatMessage::TYPE_INTERESTED:
                // Show the user's message if any, otherwise just indicate interest.
                return $text ?: 'Interested in this:';

            case ChatMessage::TYPE_PROMISED:
                $otherName = $this->getOtherUserName();
                if ($message->userid === $this->recipient->id) {
                    return "You promised this to {$otherName}:";
                }

                return "{$otherName} promised this to you:";

            case ChatMessage::TYPE_RENEGED:
                $otherName = $this->getOtherUserName();
                if ($message->userid === $this->recipient->id) {
                    return 'You cancelled your promise for:';
                }

                return "{$otherName} cancelled their promise for:";

            case ChatMessage::TYPE_COMPLETED:
                return 'This item is no longer available:';

            case ChatMessage::TYPE_ADDRESS:
                return $this->getAddressDisplayText($message);

            case ChatMessage::TYPE_NUDGE:
                if ($message->userid === $this->recipient->id) {
                    return 'You sent a nudge - please can you reply?';
                }

                return 'Nudge - please can you reply?';

            case ChatMessage::TYPE_MODMAIL:
                return 'Message from Volunteers: '.$text;

            case ChatMessage::TYPE_REPORTEDUSER:
                return 'This member reported another member with the comment: '.$text;

            case ChatMessage::TYPE_IMAGE:
                return $text ?: 'Sent an image';

            default:
                return $text ?: '(Empty message)';
        }
    }

    /**
     * Get display text for an address message.
     * The message field contains the address ID which we look up from users_addresses.
     */
    protected function getAddressDisplayText(ChatMessage $message): string
    {
        $otherUser = $this->getOtherUserName();
        $isFromRecipient = $message->userid === $this->recipient->id;

        // Build the intro text based on who sent the address.
        $intro = $isFromRecipient
            ? "You sent an address to {$otherUser}."
            : "{$otherUser} sent you an address.";

        // The message field contains the address ID.
        $addressId = intval($message->message);
        if (! $addressId) {
            return $intro;
        }

        // Look up the address.
        $userAddress = UserAddress::find($addressId);
        if (! $userAddress) {
            return $intro;
        }

        // Get the formatted multiline address.
        $formattedAddress = $userAddress->getMultiLine();
        if (! $formattedAddress) {
            return $intro;
        }

        // Build the full text with the address.
        $result = $intro."\n\n".$formattedAddress;

        // Add collection instructions if present.
        if (! empty($userAddress->instructions)) {
            $result .= "\n\n".$userAddress->instructions;
        }

        return $result;
    }

    /**
     * Get Google Maps URL for an address message.
     */
    protected function getAddressMapUrl(ChatMessage $message): ?string
    {
        if ($message->type !== ChatMessage::TYPE_ADDRESS) {
            return null;
        }

        // The message field contains the address ID.
        $addressId = intval($message->message);
        if (! $addressId) {
            return null;
        }

        // Look up the address.
        $userAddress = UserAddress::find($addressId);
        if (! $userAddress) {
            return null;
        }

        // Get coordinates (falls back to postcode if needed).
        [$lat, $lng] = $userAddress->getCoordinates();
        if (! $lat || ! $lng) {
            return null;
        }

        // Build Google Maps URL with tracked link.
        $googleMapsUrl = "https://maps.google.com/?q={$lat},{$lng}&z=16";

        return $this->trackedUrl($googleMapsUrl, 'address_map', 'map');
    }

    /**
     * Get profile image URL for a user, optimized via delivery service.
     *
     * @param  User|null  $user  The user
     * @param  int  $width  The desired width (default 40px for message avatars)
     */
    protected function getProfileImageUrl(?User $user, int $width = 40): string
    {
        // Check both for a user ID and that the user exists in the database.
        // Mock/test users may have IDs but exists=false.
        if (! $user || ! $user->id || ! $user->exists) {
            return $this->resolveAvatarUrl(null, $width);
        }

        // Get the user's profile image URL from their users_images record.
        $sourceUrl = $user->getProfileImageUrl(true);

        if (! $sourceUrl) {
            return $this->resolveAvatarUrl($user, $width);
        }

        return $this->getDeliveryUrl($sourceUrl, $width);
    }

    /**
     * Get sender profile URL for the sender section.
     */
    protected function getSenderProfileUrl(): string
    {
        return $this->getProfileImageUrl($this->sender, 40);
    }

    /**
     * Get a URL via the delivery service for image resizing/optimization.
     *
     * @param  string  $sourceUrl  The source image URL
     * @param  int  $width  The desired width
     */
    protected function getDeliveryUrl(string $sourceUrl, int $width): string
    {
        if (! $this->deliveryUrl) {
            return $sourceUrl;
        }

        return $this->deliveryUrl.'/?url='.urlencode($sourceUrl).'&w='.$width;
    }

    /**
     * Get image URL for an image message.
     */
    protected function getMessageImageUrl(ChatMessage $message): ?string
    {
        if ($message->type !== ChatMessage::TYPE_IMAGE) {
            return null;
        }

        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');

        // Chat message images use mimg_{id}.jpg format (m = message/chat).
        if ($message->imageid) {
            $sourceUrl = "{$imagesDomain}/mimg_{$message->imageid}.jpg";

            return $this->getDeliveryUrl($sourceUrl, 200);
        }

        // Try to get image from the images relationship.
        $image = $message->images()->first();
        if ($image) {
            $sourceUrl = "{$imagesDomain}/mimg_{$image->id}.jpg";

            return $this->getDeliveryUrl($sourceUrl, 200);
        }

        return null;
    }

    /**
     * Check if we should show outcome buttons.
     * Only show for Interested messages on OFFER items where recipient is the poster.
     */
    protected function shouldShowOutcomeButtons(): bool
    {
        if (! $this->refMessage) {
            return false;
        }

        // Only for OFFER items.
        if ($this->refMessage->type !== Message::TYPE_OFFER) {
            return false;
        }

        // Only if recipient is the poster.
        if ($this->refMessage->fromuser !== $this->recipient->id) {
            return false;
        }

        // Only if this is an Interested message.
        return $this->message->type === ChatMessage::TYPE_INTERESTED;
    }

    /**
     * Get outcome URLs for TAKEN/WITHDRAWN buttons.
     */
    protected function getOutcomeUrls(): array
    {
        if (! $this->refMessage) {
            return [];
        }

        $msgId = $this->refMessage->id;

        return [
            'taken' => $this->trackedUrl(
                $this->userSite.'/message/'.$msgId.'?outcome=Taken',
                'outcome_taken',
                'outcome'
            ),
            'withdrawn' => $this->trackedUrl(
                $this->userSite.'/message/'.$msgId.'?outcome=Withdrawn',
                'outcome_withdrawn',
                'outcome'
            ),
        ];
    }

    /**
     * Get the primary image URL for the referenced message.
     */
    protected function getRefMessageImageUrl(): ?string
    {
        if (! $this->refMessage) {
            return null;
        }

        // Get primary attachment for this message.
        $attachment = $this->refMessage->attachments()
            ->orderByDesc('primary')
            ->first();

        if (! $attachment) {
            return null;
        }

        // If there's an external URL, use it directly.
        if (! empty($attachment->externalurl)) {
            return $this->getDeliveryUrl($attachment->externalurl, 200);
        }

        // Build URL from image domain - message images use timg_ prefix for thumbnails.
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $sourceUrl = "{$imagesDomain}/timg_{$attachment->id}.jpg";

        return $this->getDeliveryUrl($sourceUrl, 200);
    }

    /**
     * Render the AMP version of the email.
     *
     * AMP emails allow dynamic content (fetching new messages) and
     * inline actions (replying without leaving the email client).
     */
    protected function renderAmpContent(): void
    {
        // Get the email tracking ID for analytics.
        $trackingId = $this->tracking?->id;

        // Build AMP API URLs.
        $ampChatUrl = $this->buildAmpChatUrl(
            $this->chatRoom->id,
            $this->recipient->id,
            $this->message->id, // Exclude the triggering message
            $this->message->id, // Mark messages newer than this as NEW
            $trackingId         // Track AMP render when amp-list fetches messages
        );

        $ampReplyUrl = $this->buildAmpReplyUrl(
            $this->chatRoom->id,
            $this->recipient->id,
            $trackingId
        );

        // Prepare the message with display-friendly data.
        $preparedMessage = $this->prepareMessage($this->message);

        // Render the AMP template.
        $this->renderAmpTemplate('emails.amp.chat.notification', [
            'recipient' => $this->recipient,
            'sender' => $this->sender,
            'senderName' => $this->sender?->displayname ?? 'Someone',
            'chatRoom' => $this->chatRoom,
            'chatMessage' => $preparedMessage,
            'chatUrl' => $this->chatUrl,
            'ampChatUrl' => $ampChatUrl,
            'ampReplyUrl' => $ampReplyUrl,
        ]);
    }
}
