<?php

namespace App\Mail\Admin;

use App\Mail\Contracts\DescribesMemberContent;
use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use App\Services\AdminMjmlSanitiser;
use App\Services\MjmlCompilerService;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;

class AdminMail extends MjmlMailable implements DescribesMemberContent
{
    use LoggableEmail;
    use TrackableEmail;

    public User $user;

    public ?int $adminId;

    public string $adminSubject;

    public string $adminText;

    /** The sanitised MJML part, which replaces the text in the HTML part; NULL for a text-only ADMIN. */
    public ?string $adminMjml;

    /** Set for a test send: the one address it goes to, instead of the member. */
    public ?string $testRecipient;

    /** Why the MJML part would not build, when the email fell back to the plain text. */
    public ?string $mjmlFailure = null;

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
     * @param string|null $testRecipient For a test send, the address it goes to; $user is then the moderator
     */
    public function __construct(User $user, array $admin, ?string $groupName = null, ?string $modsEmail = null, ?string $groupShort = null, array $volunteers = [], ?string $testRecipient = null)
    {
        parent::__construct();

        $this->user = $user;
        $this->adminSubject = $admin['subject'];
        $this->adminText = $admin['text'];
        $this->adminMjml = !empty($admin['mjml'])
            ? app(AdminMjmlSanitiser::class)->sanitise($admin['mjml'])
            : null;
        $this->testRecipient = $testRecipient;
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

        $this->adminId = isset($admin['id']) ? (int) $admin['id'] : null;

        // Initialize email tracking.
        $this->initTracking(
            $testRecipient ? 'AdminTest' : 'Admin',
            $testRecipient ?? $this->user->email_preferred,
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
     * Which admin this is, so lockdown:filter-spool can drop it from the send queue if the
     * admin has been withdrawn (plan 2026-09-27-lockdown-switch.md section 11.11).
     */
    public function about(): array
    {
        return [
            'chatmessages' => [],
            'messages' => [],
            'newsfeed' => [],
            'users' => [],
            'admins' => $this->adminId ? [$this->adminId] : [],
        ];
    }

    /**
     * Transactional - a moderator or admin message, not bulk mail - so it carries no List-Unsubscribe.
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
            'adminMjml' => $this->adminMjml,
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

        $result = ($this->testRecipient
                ? $this->to($this->testRecipient)
                : $this->to($this->user->email_preferred, $this->user->displayname))
            ->subject($this->getSubject());

        try {
            $result->mjmlView($mjmlView, $data, 'emails.text.admin.admin');
        } catch (\RuntimeException $e) {
            if ($this->adminMjml === null) {
                throw $e;
            }

            // The MJML part would not build. Members get the plain text instead of nothing, and a
            // test send says why at the top, so the moderator can fix it.
            $this->mjmlFailure = mb_substr($e->getPrevious()?->getMessage() ?? $e->getMessage(), 0, 500);
            Log::warning('ADMIN MJML part did not build; sending the plain text', [
                'admin_id' => $this->adminId,
                'error' => $this->mjmlFailure,
            ]);

            $this->adminMjml = null;
            $data['adminMjml'] = null;
            $data['mjmlFailure'] = $this->testRecipient ? $this->mjmlFailure : null;
            $result->mjmlView($mjmlView, $data, 'emails.text.admin.admin');
        }

        // Add reply-to for group mods.
        if ($this->modsEmail) {
            $result->replyTo($this->modsEmail);
        }

        return $result->applyLogging('Admin');
    }

    /**
     * Whether an MJML part builds on its own, once sanitised. SendAdminCommand checks this once per
     * ADMIN so that a broken one is dropped up front rather than failing for every member.
     */
    public static function mjmlBuilds(string $mjml): bool
    {
        try {
            app(MjmlCompilerService::class)->compile(
                '<mjml><mj-body>' . app(AdminMjmlSanitiser::class)->sanitise($mjml) . '</mj-body></mjml>'
            );

            return TRUE;
        } catch (\RuntimeException $e) {
            return FALSE;
        }
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
     * Get the subject line - the member's subject, with "TEST: " in front for a test send.
     */
    protected function getSubject(): string
    {
        return ($this->testRecipient ? 'TEST: ' : '') . $this->memberSubject();
    }

    /**
     * The member's subject line - "ADMIN: " for essential admins, "NEWSLETTER: " for non-essential ones, and the subject unchanged for marketing templates.
     */
    protected function memberSubject(): string
    {
        if ($this->isMarketing) {
            return $this->adminSubject;
        }

        // Strip a prefix the author typed so it is never doubled, then add "ADMIN: " for an
        // essential admin or "NEWSLETTER: " for one members can opt out of.
        $subject = preg_replace('/^(?:(?:ADMIN|NEWSLETTER):?\s+)+/', '', $this->adminSubject);

        return ($this->essential ? 'ADMIN: ' : 'NEWSLETTER: ') . $subject;
    }
}
