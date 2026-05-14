<?php

namespace App\Mail\Welcome;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Models\Group;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Per-group welcome email sent to new approved members.
 *
 * V1: Group::sendWelcome() / cron/memberships_processing.php
 *
 * Sends the group's custom welcome text (groups.welcomemail) to the
 * new member, from the group's auto-email address.
 */
class GroupWelcomeMail extends MjmlMailable
{
    use LoggableEmail;

    /**
     * @param User $user The new member.
     * @param Group $group The group they joined.
     */
    public function __construct(
        public User $user,
        public Group $group
    ) {
        parent::__construct();
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->user->id;
    }

    public function envelope(): Envelope
    {
        // Derive the per-group "auto" and "volunteers" addresses the same way
        // V1's Group::getAutoEmail()/getModsEmail() do: {nameshort}-auto@ and
        // {nameshort}-volunteers@ at the configured group domain. The `groups`
        // table has no `autoemail`/`modsemail` columns, so the previous
        // `$this->group->modsemail ?? config('freegle.mail.support')` resolved
        // to null (config key didn't exist either) and Address::__construct
        // threw "Argument #1 must be of type string, null given" for every
        // group welcome, breaking mod-welcomes silently in laravel.log.
        $groupName  = $this->group->namefull ?? $this->group->nameshort ?? config('freegle.branding.name');
        $nameshort  = $this->group->nameshort ?? '';
        $domain     = config('freegle.mail.group_domain', 'groups.ilovefreegle.org');
        $autoEmail  = $nameshort !== ''
            ? "{$nameshort}-auto@{$domain}"
            : config('freegle.mail.noreply_addr');
        $modsEmail  = $nameshort !== ''
            ? "{$nameshort}-volunteers@{$domain}"
            : config('freegle.mail.support_addr', $autoEmail);

        return new Envelope(
            from: new Address($autoEmail, "{$groupName} Volunteers"),
            replyTo: [new Address($modsEmail, "{$groupName} Volunteers")],
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        $groupName = $this->group->namefull ?? $this->group->nameshort ?? config('freegle.branding.name');
        return "Welcome to {$groupName}";
    }

    public function build(): static
    {
        $groupName = $this->group->namefull ?? $this->group->nameshort ?? config('freegle.branding.name');
        $recipientEmail = $this->user->email_preferred;
        $userSite = config('freegle.sites.user');

        // Convert plain-text line breaks to HTML line breaks for display.
        $welcomeContent = nl2br(e($this->group->welcomemail));

        return $this->mjmlView(
            'emails.mjml.welcome.group_welcome',
            [
                'groupName' => $groupName,
                'welcomeContent' => $welcomeContent,
                'email' => $recipientEmail,
                'userSite' => $userSite,
                'settingsUrl' => "{$userSite}/settings",
                'unsubscribeUrl' => "{$userSite}/unsubscribe",
            ]
        )->to($recipientEmail)
            ->applyLogging('GroupWelcome');
    }
}
