<?php

namespace App\Services\Merge;

use App\Models\User;

/**
 * Who may be merged by an offer. The Go API's merge Accept is the authority and enforces
 * these itself; the batch commands that finish or re-send offers check them first so they
 * can say why an offer was left alone.
 */
class MergeOfferRules
{
    /**
     * Why the two accounts of an offer must not be merged, or null when they may be.
     */
    public function accountsRefusal(int $id1, int $id2): ?string
    {
        if ($id1 === $id2) {
            return 'the two accounts are the same';
        }

        $u1 = User::find($id1);
        $u2 = User::find($id2);

        if (!$u1 || !$u2) {
            return 'one of the accounts no longer exists';
        }

        if ($u1->isTrashNothingAccount() || $u2->isTrashNothingAccount()) {
            return 'a TrashNothing account is never merged';
        }

        if ($this->optedOutOfMerging($u1) || $this->optedOutOfMerging($u2)) {
            return 'an account has opted out of merging';
        }

        return null;
    }

    /**
     * Same reading as the Go API: only an explicit falsy canmerge opts out.
     */
    public function optedOutOfMerging(User $user): bool
    {
        $value = ($user->settings ?? [])['canmerge'] ?? true;

        if (is_string($value)) {
            return in_array($value, ['', '0', 'false'], true);
        }

        return $value === false || $value === 0 || $value === 0.0;
    }

    /**
     * Whether the member has turned our email off or it is bouncing.
     */
    public function emailOff(User $user): bool
    {
        $simple = ($user->settings ?? [])['simplemail'] ?? null;

        return $simple === User::SIMPLE_MAIL_NONE || (int) $user->bouncing !== 0;
    }
}
