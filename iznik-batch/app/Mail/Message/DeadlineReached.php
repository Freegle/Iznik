<?php

namespace App\Mail\Message;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\Message;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class DeadlineReached extends MjmlMailable
{
    use TrackableEmail;
    use LoggableEmail;

    public Message $message;

    public User $user;

    public string $extendUrl;

    public string $completedUrl;

    public string $withdrawUrl;

    public string $outcomeType;

    /**
     * Create a new message instance.
     */
    public function __construct(Message $message, User $user)
    {
        parent::__construct();

        $this->message = $message;
        $this->user = $user;

        $userSite = config('freegle.sites.user');
        $messageId = $message->id;

        $this->extendUrl = "{$userSite}/mypost/{$messageId}/extend";
        $this->completedUrl = "{$userSite}/mypost/{$messageId}/completed";
        $this->withdrawUrl = "{$userSite}/mypost/{$messageId}/withdraw";
        $this->outcomeType = $message->type === Message::TYPE_OFFER
            ? Message::OUTCOME_TAKEN
            : Message::OUTCOME_RECEIVED;

        $group = $this->originGroupForMessage($message);

        $this->initTracking(
            'DeadlineReached',
            $user->email_preferred,
            $user->id,
            $group?->id,
            $this->getSubject(),
            ['message_id' => $message->id]
        );
    }

    /**
     * The group to show the poster for their own message: the ORIGIN group,
     * the one they actually posted to.
     *
     * Rippling adds a messages_groups row per group the post spreads into
     * (rippled_in = 1, arrival = the ripple time) and auto-joins the poster
     * there, so the old "recipient's membership with the most recent arrival"
     * pick named whichever distant group the post most recently rippled into.
     * Falls back to the earliest-arrival row for pre-rippling data.
     */
    protected function originGroupForMessage(Message $message): ?object
    {
        $groups = $message->groups;

        return $groups->first(fn ($g) => !($g->pivot->rippled_in ?? 0))
            ?? $groups->sortBy(fn ($g) => $g->pivot->arrival ?? null)->first();
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        $userSite = config('freegle.sites.user');
        $group = $this->originGroupForMessage($this->message);
        $groupName = $group?->nameshort ?? 'Freegle';

        return $this->to($this->user->email_preferred, $this->user->displayname)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.message.deadline-reached', array_merge([
                'post' => $this->message,
                'user' => $this->user,
                'outcomeType' => $this->outcomeType,
                'groupName' => $groupName,
                'extendUrl' => $this->trackedUrl($this->extendUrl, 'extend_button', 'extend'),
                'completedUrl' => $this->trackedUrl($this->completedUrl, 'completed_button', 'completed'),
                'withdrawUrl' => $this->trackedUrl($this->withdrawUrl, 'withdraw_button', 'withdraw'),
                'settingsUrl' => $this->trackedUrl("{$userSite}/settings", 'footer_settings', 'settings'),
                'email' => $this->user->email_preferred,
            ], $this->getTrackingData()))
            ->applyLogging('DeadlineReached');
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

    /**
     * Get the subject line.
     */
    protected function getSubject(): string
    {
        return 'Deadline reached: ' . $this->message->subject;
    }

    /**
     * Transactional - about the member's own post - so it carries no List-Unsubscribe.
     */
    protected function unsubscribeType(): ?string
    {
        return null;
    }

    /**
     * Get the recipient's user ID for common header tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }
}
