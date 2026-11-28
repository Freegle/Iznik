<?php

namespace App\Services\Merge;

use App\Mail\Session\MergeOfferMail;
use App\Models\User;
use App\Services\EmailSpoolerService;

/**
 * Sends a merge offer to both accounts of the offer. Used for the first email and for the
 * reminder, so they are the same email.
 */
class MergeOfferMailer
{
    /**
     * @return int how many emails were spooled
     */
    public function send(
        int $mergeId,
        string $uid,
        User $u1,
        User $u2,
        EmailSpoolerService $spooler,
        ?string $intro = null,
    ): int {
        $mergeUrl = config('freegle.sites.user') . '/merge?id=' . $mergeId . '&uid=' . $uid;
        $name1 = $u1->fullname ?: 'Freegle User';
        $name2 = $u2->fullname ?: 'Freegle User';
        $email1 = $this->obfuscateEmail($u1->email_preferred ?? '');
        $email2 = $this->obfuscateEmail($u2->email_preferred ?? '');
        $sent = 0;

        foreach ([$u1, $u2] as $recipient) {
            $recipientEmail = $recipient->email_preferred;

            if (!$recipientEmail) {
                continue;
            }

            $mail = new MergeOfferMail(
                recipientUserId: $recipient->id,
                recipientName: $recipient->fullname ?: 'Freegle User',
                recipientEmail: $recipientEmail,
                name1: $name1,
                email1: $email1,
                name2: $name2,
                email2: $email2,
                mergeUrl: $mergeUrl,
                intro: $intro,
            );

            $spooler->spool($mail, $recipientEmail);
            $sent++;
        }

        return $sent;
    }

    public function obfuscateEmail(string $email): string
    {
        if (!$email || !str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        if (strlen($local) <= 1) {
            return '*@' . $domain;
        }

        return $local[0] . str_repeat('*', strlen($local) - 1) . '@' . $domain;
    }
}
