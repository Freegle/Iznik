<?php

namespace App\Mail\Deferrals;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Services\UnsubscribeService;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "You have unread messages", sent once after a deferral is released.
 *
 * While a provider is refusing our mail we stop generating chat
 * notifications entirely rather than pile them into a queue that cannot
 * drain. When the provider starts accepting us again the member may have
 * missed days of replies, and replaying every one of them would land a stack
 * of stale notifications in a mailbox all at once - which is exactly the
 * behaviour that gets a sender deferred in the first place.
 *
 * So: one email, saying there are messages waiting and where to read them.
 *
 * "Where" is two places. Chats with other members are read on the member
 * site. A moderator's chats on the volunteers' side of their groups (and
 * mod-to-mod chats) are read in ModTools, and the member site does not list
 * them at all - so each half of the summary carries its own link, and a half
 * with nothing in it is left out.
 */
class UnreadChatCatchUpMail extends MjmlMailable
{
    use LoggableEmail;
    use TrackableEmail;

    public function __construct(
        public readonly int $recipientUserId,
        public readonly string $recipientEmail,
        public readonly string $recipientName,
        public readonly int $chatCount,
        public readonly int $messageCount,
        public readonly string $delayedSince,
        public readonly ?string $provider,
        public readonly int $modChatCount = 0,
        public readonly int $modMessageCount = 0,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        $chats = $this->chatCount + $this->modChatCount;

        if ($chats === 1) {
            return 'You have unread messages on '.config('freegle.branding.name');
        }

        return "You have unread messages in {$chats} chats on ".config('freegle.branding.name');
    }

    /**
     * Chat mail, so it carries the chat unsubscribe like any other chat
     * notification would have done.
     */
    protected function unsubscribeType(): ?string
    {
        return UnsubscribeService::TYPE_CHAT;
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->recipientUserId;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            to: [new Address($this->recipientEmail, $this->recipientName)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $userSite = rtrim((string) config('freegle.sites.user'), '/');
        $modSite = rtrim((string) config('freegle.sites.mod'), '/');
        $chatsUrl = $this->trackedUrl($userSite . '/chats', 'catchup_chats', 'chats');
        $modChatsUrl = $this->trackedUrl($modSite . '/chats', 'catchup_modchats', 'modchats');
        $settingsUrl = $this->trackedUrl($userSite . '/settings', 'footer_settings', 'settings');

        return $this->mjmlView('emails.mjml.deferrals.unread-chat-catchup', array_merge([
            'recipientName' => $this->recipientName,
            'email' => $this->recipientEmail,
            'chatCount' => $this->chatCount,
            'messageCount' => $this->messageCount,
            'modChatCount' => $this->modChatCount,
            'modMessageCount' => $this->modMessageCount,
            'delayedSince' => $this->delayedSince,
            'provider' => $this->provider,
            'chatsUrl' => $chatsUrl,
            'modChatsUrl' => $modChatsUrl,
            'settingsUrl' => $settingsUrl,
            'userSite' => $userSite,
        ], $this->getTrackingData()), 'emails.text.deferrals.unread-chat-catchup')
            ->applyLogging('UnreadChatCatchUp');
    }
}
