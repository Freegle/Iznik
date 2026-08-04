<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\User;
use App\Models\UserEmail;

/**
 * Generates the moderator-list CSV rows.
 *
 * Port of the V1 script scripts/fix/fix_listmods.php: one row per user who
 * is a Moderator or Owner of any group, with their preferred email, name,
 * last access date, active/backup mod group lists and other known emails.
 *
 * Deliberate deviation from V1: getName()'s invent-a-name branch (which
 * generated and STORED a replacement for blank/placeholder names) is
 * omitted - a listing must not write to the database, so such rare names
 * pass through unchanged.
 */
class ListModsService
{
    public const HEADER = [
        'userid',
        'email',
        'name',
        'lastaccess',
        'activemod',
        'backupmod',
        'other known emails',
    ];

    /**
     * @return \Generator<int, array> header row first, then one row per mod
     */
    public function rows(): \Generator
    {
        yield self::HEADER;

        $modIds = Membership::query()
            ->whereIn('role', [Membership::ROLE_MODERATOR, Membership::ROLE_OWNER])
            ->distinct()
            ->pluck('userid');

        foreach ($modIds as $userid) {
            $user = User::find($userid);

            if (!$user) {
                continue;
            }

            // V1 ordered by preferred DESC, email ASC - the alphabetical
            // tiebreak matters, so don't reuse User::getEmailPreferredAttribute
            // (which breaks ties on validated DESC).
            $emails = UserEmail::query()
                ->where('userid', $user->id)
                ->orderByDesc('preferred')
                ->orderBy('email')
                ->pluck('email');

            $preferred = NULL;

            foreach ($emails as $candidate) {
                if (!User::isInternalEmail($candidate)) {
                    $preferred = $candidate;
                    break;
                }
            }

            // V1 filtered "other known emails" on Mail::ourDomain() alone,
            // NOT the wider preferred-email filter - so e.g. a yahoogroups
            // address appears here even though it can never be preferred.
            $otherEmails = [];

            foreach ($emails as $candidate) {
                if (!$this->isOurDomain($candidate) && $candidate !== $preferred) {
                    $otherEmails[] = $candidate;
                }
            }

            [$active, $backup] = $this->modGroups($user->id);

            yield [
                $user->id,
                $preferred,
                $this->name($user),
                $user->lastaccess ? $user->lastaccess->format('Y-m-d') : NULL,
                implode(',', $active),
                implode(',', $backup),
                implode(',', $otherEmails),
            ];
        }
    }

    /**
     * The user's mod/owner groups split into active and backup, ordered as
     * V1 getMemberships did: LOWER(namefull ?? nameshort) ASC.  V1 CLI
     * scripts ran with Session::modtools() TRUE, so there is deliberately
     * no groups.publish filter here.
     *
     * @return array{0: string[], 1: string[]} [active nameshorts, backup nameshorts]
     */
    private function modGroups(int $userid): array
    {
        $modships = Membership::query()
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $userid)
            ->whereIn('memberships.role', [Membership::ROLE_MODERATOR, Membership::ROLE_OWNER])
            ->orderByRaw('LOWER(COALESCE(groups.namefull, groups.nameshort)) ASC')
            ->select('memberships.*', 'groups.nameshort AS group_nameshort')
            ->get();

        $active = [];
        $backup = [];

        foreach ($modships as $modship) {
            if ($modship->isActiveMod()) {
                $active[] = $modship->group_nameshort;
            } else {
                $backup[] = $modship->group_nameshort;
            }
        }

        return [$active, $backup];
    }

    /**
     * V1 User::getName() minus the invent-a-name branch: fullname else
     * first/last, strip from '@', truncate long names, disambiguate purely
     * numeric ones, drop the TrashNothing -gNNN suffix - in that order.
     */
    private function name(User $user): ?string
    {
        $name = NULL;

        if ($user->fullname) {
            $name = $user->fullname;
        } elseif ($user->firstname || $user->lastname) {
            $name = $user->firstname && $user->lastname
                ? "{$user->firstname} {$user->lastname}"
                : ($user->firstname ?: $user->lastname);
        }

        if ($name === NULL) {
            return NULL;
        }

        if (str_contains($name, '@')) {
            $name = substr($name, 0, strpos($name, '@'));
        }

        $name = strlen($name) > 32 ? (substr($name, 0, 32) . '...') : $name;
        $name = is_numeric($name) ? "$name." : $name;

        return User::removeTNGroup($name);
    }

    /**
     * V1 Mail::ourDomain(): internal domains only, without the extra
     * yahoogroups/group-domain patterns used for preferred-email selection.
     */
    private function isOurDomain(string $email): bool
    {
        foreach (config('freegle.mail.internal_domains', []) as $domain) {
            if (stripos($email, '@' . $domain) !== FALSE) {
                return TRUE;
            }
        }

        return FALSE;
    }
}
