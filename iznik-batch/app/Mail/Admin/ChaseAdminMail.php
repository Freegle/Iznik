<?php

namespace App\Mail\Admin;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class ChaseAdminMail extends MjmlMailable
{
    use TrackableEmail;
    use LoggableEmail;
    public ?User $user;

    public string $adminSubject;

    public string $groupName;

    public int $pendingHours;

    public int $adminId;

    public string $modToolsUrl;

    public string $pendingTimeText;

    /**
     * @param User|null $user Recipient moderator (null for test emails)
     * @param string $adminSubject Subject of the pending admin
     * @param string $groupName Group name for display
     * @param int $pendingHours How many hours the admin has been pending
     * @param int $adminId Admin ID for linking to ModTools
     */
    public function __construct(
        ?User $user,
        string $adminSubject,
        string $groupName,
        int $pendingHours,
        int $adminId
    ) {
        parent::__construct();

        $this->user = $user;
        $this->adminSubject = $adminSubject;
        $this->groupName = $groupName;
        $this->pendingHours = $pendingHours;
        $this->adminId = $adminId;
        $this->modToolsUrl = config('freegle.sites.mod', 'https://modtools.org') . '/admins';
        $this->pendingTimeText = $this->formatPendingTime($pendingHours);

        $this->initTracking(
            'ChaseAdmin',
            $user?->email_preferred ?? '',
            $user?->id,
            null,
            $this->getSubject(),
            ['admin_id' => $adminId, 'group' => $groupName]
        );
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }

    public function build(): static
    {
        $data = [
            'adminSubject' => $this->adminSubject,
            'groupName' => $this->groupName,
            'pendingHours' => $this->pendingHours,
            'pendingTimeText' => $this->pendingTimeText,
            'modToolsUrl' => $this->modToolsUrl,
            'adminId' => $this->adminId,
            'userName' => $this->user ? ($this->user->firstname ?: ($this->user->fullname ?: 'there')) : 'there',
        ];

        $result = $this
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.admin.chase', array_merge($data, $this->getTrackingData()), 'emails.text.admin.chase');

        if ($this->user) {
            $result->to($this->user->email_preferred, $this->user->fullname);
        }

        return $result->applyLogging('ChaseAdmin');
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
        return "ADMIN: Action needed - pending suggested admin for {$this->groupName}";
    }

    /**
     * Format pending hours into a human-readable string.
     * E.g. "2 days and 3 hours", "1 day and 12 hours", "5 days".
     */
    protected function formatPendingTime(int $hours): string
    {
        $hours = abs($hours);

        $days = intdiv($hours, 24);
        $remainingHours = $hours % 24;

        if ($days === 0) {
            return "{$hours} hour" . ($hours !== 1 ? 's' : '');
        }

        $dayText = "{$days} day" . ($days !== 1 ? 's' : '');

        if ($remainingHours === 0) {
            return $dayText;
        }

        $hourText = "{$remainingHours} hour" . ($remainingHours !== 1 ? 's' : '');

        return "{$dayText} and {$hourText}";
    }
}
