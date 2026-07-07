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

        $group = $this->recipientGroupForMessage($message, $user);

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
     * Pick the group most relevant to the recipient for a message.
     *
     * Prefers a group the recipient is a member of, sorted by most-recent
     * arrival. Falls back to the first group if there is no overlap.
     */
    protected function recipientGroupForMessage(Message $message, User $user): ?object
    {
        $userGroupIds = $user->memberships->pluck('groupid')->map(fn ($id) => (int) $id)->all();

        return $message->groups
            ->filter(fn ($g) => in_array((int) $g->id, $userGroupIds, true))
            ->sortByDesc(fn ($g) => $g->pivot->arrival ?? null)
            ->first()
            ?? $message->groups->first();
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        $userSite = config('freegle.sites.user');
        $group = $this->recipientGroupForMessage($this->message, $this->user);
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
     * Get the recipient's user ID for common header tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }
}
