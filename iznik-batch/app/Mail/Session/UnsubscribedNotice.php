<?php

namespace App\Mail\Session;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Services\LoginLinkService;
use App\Services\UnsubscribeService;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Acknowledgement sent when someone unsubscribes by emailing the address in our
 * List-Unsubscribe header.
 *
 * It confirms what we turned off, says plainly which other kinds of email may still
 * arrive, and points at Settings for changing any of it. Before this existed, that
 * mailto: went to noreply@ilovefreegle.org - a Google Workspace mailbox - and the member
 * got an auto-reply saying the mailbox was not monitored and to contact support, which
 * reads as "you did something wrong" in response to a perfectly normal unsubscribe.
 */
class UnsubscribedNotice extends MjmlMailable
{
    use LoggableEmail;

    /**
     * @param  string[]  $turnedOff  Categories actually switched off by this unsubscribe.
     * @param  string[]  $stillOn  Categories still switched on afterwards.
     */
    public function __construct(
        protected int $userId,
        protected string $recipientEmail,
        protected ?string $recipientName,
        protected string $type,
        protected array $turnedOff,
        protected array $stillOn,
    ) {
        parent::__construct();

        // Set here rather than in build(), so the recipient is known as soon as the
        // mailable exists - Laravel only calls build() when it actually renders.
        $this->to($this->recipientEmail, $this->recipientName);
    }

    /**
     * This is a direct acknowledgement of something the member just asked for, so it is
     * transactional: it carries no List-Unsubscribe of its own, and it is sent even to
     * someone who has just turned everything off.
     */
    protected function unsubscribeType(): ?string
    {
        return null;
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            replyTo: [new Address(config('freegle.mail.support_addr'))],
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return $this->type === UnsubscribeService::TYPE_ALL
            ? "We've turned off your Freegle emails"
            : "We've turned off ".UnsubscribeService::describe($this->type);
    }

    /**
     * One tap to stop everything.
     *
     * Someone who turned off one kind of email and finds they still get others should not
     * have to go and hunt through Settings - that is the frustration behind most "I
     * unsubscribed and you're still emailing me" reports. Points at the same
     * key-authenticated apiv2 endpoint the header uses, so it needs no login: a GET applies
     * the opt-out and renders a confirmation.
     */
    protected function stopAllUrl(): string
    {
        $apiV2 = rtrim((string) config('freegle.api.v2_url', 'https://api.ilovefreegle.org/apiv2'), '/');

        return $apiV2.'/user/unsubscribe?'.http_build_query([
            'u' => $this->userId,
            'k' => app(LoginLinkService::class)->getOrCreateKey($this->userId),
            't' => UnsubscribeService::TYPE_ALL,
        ]);
    }

    public function build(): static
    {
        $userSite = rtrim((string) config('freegle.sites.user'), '/');

        $data = [
            'recipientName' => $this->recipientName,
            'recipientEmail' => $this->recipientEmail,
            'turnedOff' => array_map(
                fn (string $t) => UnsubscribeService::describe($t),
                $this->turnedOff
            ),
            'stillOn' => array_map(
                fn (string $t) => UnsubscribeService::describe($t),
                $this->stillOn
            ),
            'alreadyOff' => empty($this->turnedOff),
            'whatTheyAskedFor' => UnsubscribeService::describe($this->type),
            'settingsUrl' => $userSite.'/settings',
            'unsubscribeUrl' => $userSite.'/unsubscribe',
            'stopAllUrl' => $this->stopAllUrl(),
            'everythingAlreadyOff' => empty($this->stillOn),
        ];

        return $this->mjmlView('emails.mjml.session.unsubscribed-notice', $data, 'emails.text.session.unsubscribed-notice')
            ->applyLogging('UnsubscribedNotice');
    }
}
