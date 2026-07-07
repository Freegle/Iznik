<?php

namespace App\Mail\Message;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\Message;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class ChaseUp extends MjmlMailable
{
    use TrackableEmail;
    use LoggableEmail;

    /**
     * Create a new message instance.
     *
     * V1: "What happened to: {subject}" chase-up email sent after max reposts
     * reached and a reply exists. Asks user to mark outcome, repost, or withdraw.
     */
    public function __construct(
        public int $messageId,
        public string $messageSubject,
        public string $messageType,
        public int $userId,
        public string $userName,
        public string $userEmail,
        public ?int $groupId = null,
    ) {
        parent::__construct();

        $this->initTracking(
            'ChaseUp',
            $this->userEmail,
            $this->userId,
            $this->groupId,
            $this->getSubject(),
            ['message_id' => $this->messageId]
        );
    }

    public function build(): static
    {
        $userSite = config('freegle.sites.user');
        $outcomeType = $this->messageType === Message::TYPE_OFFER
            ? Message::OUTCOME_TAKEN
            : Message::OUTCOME_RECEIVED;

        return $this->to($this->userEmail, $this->userName)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.message.chaseup', array_merge([
                'userName' => $this->userName,
                'messageSubject' => $this->messageSubject,
                'outcomeType' => $outcomeType,
                'repostUrl' => $this->trackedUrl(
                    "{$userSite}/mypost/{$this->messageId}/repost",
                    'repost_button',
                    'repost'
                ),
                'completedUrl' => $this->trackedUrl(
                    "{$userSite}/mypost/{$this->messageId}/completed",
                    'completed_button',
                    'completed'
                ),
                'withdrawUrl' => $this->trackedUrl(
                    "{$userSite}/mypost/{$this->messageId}/withdraw",
                    'withdraw_button',
                    'withdraw'
                ),
                'chatsUrl' => $this->trackedUrl(
                    "{$userSite}/chats",
                    'chats_link',
                    'chats'
                ),
                'myPostsUrl' => $this->trackedUrl(
                    "{$userSite}/myposts",
                    'myposts_link',
                    'myposts'
                ),
                'settingsUrl' => $this->trackedUrl(
                    "{$userSite}/settings",
                    'footer_settings',
                    'settings'
                ),
                'email' => $this->userEmail,
            ], $this->getTrackingData()))
            ->applyLogging('ChaseUp');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return 'What happened to: ' . $this->messageSubject;
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }
}
