<?php

namespace App\Mail\Message;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email sent from a moderator to a message poster using a standard message.
 * Used for approve, reject, reply, and delete actions.
 *
 * V1 parity: sent from {groupnameshort}-volunteers@groups.ilovefreegle.org
 * (or the group's contactmail if set), with the mod's display name.
 */
class ModStdMessageMail extends MjmlMailable
{
    use TrackableEmail;
    use LoggableEmail;

    protected string $mjmlTemplate = 'emails.mjml.message.mod-std-message';

    public function __construct(
        public readonly string $modName,
        public readonly string $groupName,
        public readonly string $groupNameShort,
        public readonly string $stdSubject,
        public readonly string $stdBody,
        public readonly string $messageSubject,
        public readonly int $msgId,
        public readonly int $recipientUserId,
        public readonly string $recipientEmail = '',
        public readonly ?string $groupContactMail = null,
    ) {
        parent::__construct();

        $this->initTracking(
            'ModStdMessage',
            $this->recipientEmail,
            $this->recipientUserId,
            null,
            $this->stdSubject,
            ['message_id' => $this->msgId]
        );

        $this->mjmlData = array_merge([
            'modName' => $this->modName,
            'groupName' => $this->groupName,
            'body' => $this->stdBody,
            'messageSubject' => $this->messageSubject,
            'userSite' => config('freegle.sites.user'),
            'email' => $this->recipientEmail,
        ], $this->getTrackingData());
    }

    public function envelope(): Envelope
    {
        // V1: from address is group's contactmail, or {nameshort}-volunteers@groups.ilovefreegle.org
        $fromEmail = $this->groupContactMail
            ?: ($this->groupNameShort . '-volunteers@' . config('freegle.mail.group_domain', 'groups.ilovefreegle.org'));

        return new Envelope(
            from: new Address($fromEmail, $this->modName),
            subject: $this->stdSubject,
        );
    }

    public function getSubject(): string
    {
        return $this->stdSubject;
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->recipientUserId;
    }
}
