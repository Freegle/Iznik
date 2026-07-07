<?php

namespace App\Mail\Admin;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class AdminMail extends MjmlMailable
{
    use LoggableEmail;
    use TrackableEmail;

    public User $user;

    public string $adminSubject;

    public string $adminText;

    public ?string $ctaLink;

    public ?string $ctaText;

    public ?string $groupName;

    public ?string $modsEmail;

    public bool $essential;

    public string $userSite;

    public string $settingsUrl;

    public ?string $marketingOptOutUrl;

    public bool $isMarketing;

    public ?string $template;

    public ?string $groupShort;

    public array $volunteers;

    /**
     * Create a new message instance.
     *
     * @param User $user Recipient user
     * @param array $admin Admin record (from admins table)
     * @param string|null $groupName Group name for footer
     * @param string|null $modsEmail Group mods email for reply-to
     * @param string|null $groupShort Group's nameshort for from address
     * @param array $volunteers Local volunteers [{id, displayname, firstname}, ...]
     */
    public function __construct(User $user, array $admin, ?string $groupName = null, ?string $modsEmail = null, ?string $groupShort = null, array $volunteers = [])
    {
        parent::__construct();

        $this->user = $user;
        $this->adminSubject = $admin['subject'];
        $this->adminText = $admin['text'];
        $this->ctaLink = $admin['ctalink'] ?? null;
        $this->ctaText = $admin['ctatext'] ?? null;
        $this->groupName = $groupName;
        $this->modsEmail = $modsEmail;
        $this->essential = (bool) ($admin['essential'] ?? true);
        $this->template = $admin['template'] ?? null;
        $this->isMarketing = !empty($this->template);
        $this->groupShort = $groupShort;
        $this->volunteers = $volunteers;
        $this->userSite = config('freegle.sites.user');

        // Marketing opt-out shown for non-essential admins.
        $this->marketingOptOutUrl = !$this->essential ? $user->marketingOptOutUrl() : null;

        // Initialize email tracking.
        $this->initTracking(
            'Admin',
            $this->user->email_preferred,
            $this->user->id,
            $admin['groupid'] ?? null,
            $this->adminSubject,
            [
                'admin_id' => $admin['id'] ?? null,
                'parent_id' => $admin['parentid'] ?? null,
                'essential' => $this->essential,
            ]
        );
    }

    /**
     * Get the recipient's user ID for common header tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        $this->settingsUrl = $this->trackedUrl(
            $this->userSite . '/settings',
            'footer_settings',
            'settings'
        );

        $data = array_merge([
            'user' => $this->user,
            'userSite' => $this->userSite,
            'adminSubject' => $this->adminSubject,
            'adminText' => $this->adminText,
            'ctaLink' => $this->ctaLink ? $this->trackedUrl($this->ctaLink, 'cta_button', 'cta') : null,
            'ctaText' => $this->ctaText,
            'groupName' => $this->groupName,
            'modsEmail' => $this->modsEmail,
            'essential' => $this->essential,
            'settingsUrl' => $this->settingsUrl,
            'marketingOptOutUrl' => $this->marketingOptOutUrl
                ? $this->trackedUrl($this->marketingOptOutUrl, 'marketing_optout', 'optout')
                : null,
            'unsubscribeUrl' => $this->trackedUrl(
                $this->userSite . '/unsubscribe',
                'footer_unsubscribe',
                'unsubscribe'
            ),
            'volunteers' => $this->volunteers,
        ], $this->getTrackingData());

        // A marketing/template email renders emails.mjml.admin.<template>, which supplies its own
        // hero data. (The one-off "Little Free Shop 2026" appeal template and its hardcoded hero
        // block were removed once the campaign was over; the generic template mechanism remains.)
        $mjmlView = $this->isMarketing ? "emails.mjml.admin.{$this->template}" : 'emails.mjml.admin.admin';

        $result = $this->to($this->user->email_preferred, $this->user->displayname)
            ->subject($this->getSubject())
            ->mjmlView($mjmlView, $data, 'emails.text.admin.admin');

        // Add reply-to for group mods.
        if ($this->modsEmail) {
            $result->replyTo($this->modsEmail);
        }

        return $result->applyLogging('Admin');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        // V1 sends from {groupshort}-auto@groups.ilovefreegle.org with display name "{GroupName} Volunteers".
        if ($this->groupShort) {
            $fromAddress = "{$this->groupShort}-auto@groups.ilovefreegle.org";
            $fromName = ($this->groupName ?? $this->groupShort) . ' Volunteers';
        } else {
            $fromAddress = config('freegle.mail.noreply_addr');
            $fromName = config('freegle.branding.name');
        }

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: $this->getSubject(),
        );
    }

    /**
     * Get the subject line - prepends "ADMIN: " prefix for admin emails, but not for marketing/newsletters.
     */
    protected function getSubject(): string
    {
        if ($this->isMarketing) {
            return $this->adminSubject;
        }

        return 'ADMIN: ' . $this->adminSubject;
    }
}
