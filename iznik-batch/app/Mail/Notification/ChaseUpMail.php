<?php

namespace App\Mail\Notification;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email sent when a user has unseen, unmailed notifications on Freegle
 * (comments on posts, loves, etc.). Migrated from V1 Notifications::sendEmails()
 * via notification_chaseup.php.
 */
class ChaseUpMail extends MjmlMailable
{
    use LoggableEmail;
    use TrackableEmail;

    public User $recipient;

    /** @var array<int, array<string, mixed>> Prepared notification rows from NotificationChaseUpService */
    public array $notifications;

    public string $userSite;

    public string $chitchatUrl;

    private string $subjectLine;

    /**
     * @param User $recipient        Recipient user
     * @param array $notifications   Prepared notification data (from NotificationChaseUpService)
     * @param string $subject        Pre-computed subject line
     */
    public function __construct(User $recipient, array $notifications, string $subject)
    {
        parent::__construct();

        $this->recipient     = $recipient;
        $this->notifications = $notifications;
        $this->subjectLine   = $subject;
        $this->userSite      = config('freegle.sites.user');
        $this->chitchatUrl   = $this->userSite . '/chitchat';

        $userId = $this->recipient->exists ? $this->recipient->id : null;

        $this->initTracking(
            'NotificationChaseUp',
            $this->recipient->email_preferred,
            $userId,
            null,
            $this->subjectLine
        );
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->recipient->id ?? null;
    }

    protected function getSubject(): string
    {
        return $this->subjectLine;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name', 'Freegle')
            ),
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $count       = count($this->notifications);
        $settingsUrl = $this->trackedUrl($this->userSite . '/settings', 'footer_settings', 'settings');
        $unsubUrl    = $this->trackedUrl($this->userSite . '/unsubscribe', 'footer_unsubscribe', 'unsubscribe');

        // Build tracked URLs per notification — mutate public property so text view sees them too.
        $this->notifications = array_map(function ($notif) {
            $url = ($notif['type'] === 'Exhort' && $notif['url'])
                ? $this->userSite . $notif['url']
                : ($notif['newsfeed'] ? $this->userSite . '/chitchat/' . $notif['newsfeed']['id'] : $this->chitchatUrl);

            $notif['trackedUrl'] = $this->trackedUrl($url, 'notification_' . $notif['id'], 'view');

            return $notif;
        }, $this->notifications);

        return $this->to($this->recipient->email_preferred, $this->recipient->displayname)
            ->subject($this->getSubject())
            ->mjmlView(
                'emails.mjml.notification.chaseup',
                array_merge([
                    'recipient'     => $this->recipient,
                    'notifications' => $this->notifications,
                    'count'         => $count,
                    'userSite'      => $this->userSite,
                    'chitchatUrl'   => $this->trackedUrl($this->chitchatUrl, 'header_chitchat', 'view'),
                    'settingsUrl'   => $settingsUrl,
                    'unsubscribeUrl' => $unsubUrl,
                    'email'          => $this->recipient->email_preferred,
                ], $this->getTrackingData()),
                'emails.text.notification.chaseup'
            )
            ->applyLogging('NotificationChaseUp');
    }
}
