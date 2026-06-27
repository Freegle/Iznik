<?php

namespace App\Mail\Ripple;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Models\Message;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One bundled "your post is reaching nearby communities" intro email, sent once per post the
 * first time Rippling Out auto-joins the poster to a community their post has rippled into.
 *
 * Replaces the per-group welcome storm (a post can ripple into many groups; sending one welcome
 * per group would flood the inbox). Its job is transparency + control: explain what happened, the
 * email defaults we applied on their behalf (daily digest for the rippled communities; events and
 * volunteering left as their normal setting), and how to change them or leave - not to "welcome"
 * them to a specific group. Sent from the Freegle-wide no-reply address, not a group address.
 */
class RippleIntroMail extends MjmlMailable
{
    use LoggableEmail;

    /**
     * @param User                                  $user          The poster who has been auto-joined.
     * @param Message|null                          $message       The post that rippled (for light context).
     * @param array<int,array{name:string,welcome:string}> $welcomeGroups The communities the post was joined
     *        to that have a custom welcome message - each community's own welcome text is shown in the
     *        bundled intro, so the poster still sees what each community wanted to say (without N separate
     *        welcome emails).
     */
    public function __construct(
        public User $user,
        public ?Message $message = null,
        public array $welcomeGroups = []
    ) {
        parent::__construct();
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->user->id;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return 'Your ' . config('freegle.branding.name', 'Freegle') . ' post is reaching more people';
    }

    public function build(): static
    {
        $recipientEmail = $this->user->email_preferred;
        $userSite = config('freegle.sites.user');

        // Normalise each community's welcome entry to {name, welcome} (raw text). The HTML
        // template converts line breaks with nl2br; the plain-text template uses it as-is.
        $welcomeGroups = array_map(static fn ($g) => [
            'name' => $g['name'] ?? '',
            'welcome' => $g['welcome'] ?? '',
        ], $this->welcomeGroups);

        return $this->mjmlView(
            'emails.mjml.ripple.intro',
            [
                'firstName' => $this->user->firstname ?? null,
                // The post that rippled, so the preheader can name it in the inbox preview.
                'postSubject' => $this->message?->subject,
                'email' => $recipientEmail,
                'userSite' => $userSite,
                'settingsUrl' => "{$userSite}/settings",
                'unsubscribeUrl' => "{$userSite}/unsubscribe",
                'welcomeGroups' => $welcomeGroups,
            ],
            'emails.text.ripple.intro'
        )->to($recipientEmail)
            ->applyLogging('RippleIntro');
    }
}
