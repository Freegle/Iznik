<?php

namespace App\Services;

use App\Mail\Birthday\BirthdayMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class BirthdayService
{
    /**
     * Send birthday emails to members of groups founded on today's date.
     *
     * @param  string|null  $emailOverride  Send test email to this address and stop after one
     * @param  int[]|null  $groupIds  Only process these specific group IDs
     */
    public function sendBirthdayEmails(?string $emailOverride = null, ?array $groupIds = null, bool $dryRun = false): int
    {
        $today = now()->format('m-d');

        $query = DB::table('groups')
            ->whereRaw("DATE_FORMAT(founded, '%m-%d') = ?", [$today])
            ->where('type', 'Freegle')
            ->where('publish', 1)
            ->where('onmap', 1)
            ->whereRaw('YEAR(NOW()) - YEAR(founded) > 0')
            ->select(['id', 'nameshort', 'namefull', 'contactmail', DB::raw('YEAR(NOW()) - YEAR(founded) AS age')]);

        if ($groupIds !== null) {
            $query->whereIn('id', $groupIds);
        }

        $groups = $query->orderBy('nameshort')->get();

        $count = 0;

        foreach ($groups as $group) {
            $fromEmail = $group->contactmail
                ? $group->contactmail
                : ($group->nameshort . '-volunteers@' . config('freegle.mail.group_domain'));

            $fromName = $group->namefull ?? $group->nameshort;

            $volunteers = $this->getActiveVolunteers($group->id);

            $members = DB::table('users')
                ->join('memberships', 'users.id', '=', 'memberships.userid')
                ->where('memberships.groupid', $group->id)
                ->where('memberships.collection', 'Approved')
                ->whereNull('users.deleted')
                ->where('users.marketingconsent', 1)
                ->select('users.id', 'users.settings')
                ->distinct()
                ->get();

            foreach ($members as $member) {
                $recipientEmail = $emailOverride ?? $this->getPreferredEmail($member->id);

                if (!$recipientEmail) {
                    continue;
                }

                if (!$emailOverride && !$this->canReceiveOurMails($member->id)) {
                    continue;
                }

                if (!$emailOverride && $this->sentBirthdayAppealRecently($member->settings)) {
                    continue;
                }

                if (!$dryRun) {
                    Mail::send(new BirthdayMail(
                        groupName: $fromName,
                        groupNameShort: $group->nameshort,
                        groupAge: (int) $group->age,
                        groupId: $group->id,
                        recipientEmail: $recipientEmail,
                        fromEmail: $fromEmail,
                        fromName: $fromName,
                        volunteers: $volunteers,
                    ));

                    if (!$emailOverride) {
                        $this->recordBirthdayAppealSent($member->id, $member->settings);
                    }
                }

                $count++;

                if ($emailOverride) {
                    return $count;
                }
            }
        }

        return $count;
    }

    private function getActiveVolunteers(int $groupId): array
    {
        $oneYearAgo = now()->subYear()->format('Y-m-d H:i:s');

        $mods = DB::table('memberships')
            ->join('users', 'users.id', '=', 'memberships.userid')
            ->where('memberships.groupid', $groupId)
            ->where('memberships.collection', 'Approved')
            ->whereIn('memberships.role', ['Moderator', 'Owner'])
            ->whereNull('users.deleted')
            ->where('users.lastaccess', '>=', $oneYearAgo)
            ->where('users.publishconsent', 1)
            ->select(['users.id', 'users.fullname', 'users.firstname'])
            ->get();

        return $mods->map(function ($mod) {
            $displayName = $mod->fullname ?? $mod->firstname ?? 'Volunteer';
            $firstName = explode(' ', $displayName)[0];
            return ['firstname' => $firstName, 'displayname' => $displayName];
        })->toArray();
    }

    private function getPreferredEmail(int $userId): ?string
    {
        return DB::table('users_emails')
            ->where('userid', $userId)
            ->where('preferred', 1)
            ->value('email');
    }

    private function canReceiveOurMails(int $userId): bool
    {
        $user = DB::table('users')
            ->where('id', $userId)
            ->select(['deleted', 'settings', 'bouncing', 'onholidaytill', 'lastaccess'])
            ->first();

        if (!$user || $user->deleted) {
            return false;
        }

        if ($user->bouncing) {
            return false;
        }

        if ($user->onholidaytill && strtotime($user->onholidaytill) > time()) {
            return false;
        }

        // Skip users inactive for more than 6 months (matches V1 Engage::USER_INACTIVE).
        if (!$user->lastaccess || (time() - strtotime($user->lastaccess)) > 183 * 24 * 60 * 60) {
            return false;
        }

        $settings = $user->settings ? json_decode($user->settings, true) : [];

        return $settings['notifications']['email'] ?? true;
    }

    private function sentBirthdayAppealRecently(?string $settingsJson): bool
    {
        $settings = $settingsJson ? json_decode($settingsJson, true) : [];
        $last = $settings['lastbirthdayappeal'] ?? null;

        if (!$last) {
            return false;
        }

        return (time() - strtotime($last)) < 31 * 24 * 60 * 60;
    }

    private function recordBirthdayAppealSent(int $userId, ?string $settingsJson): void
    {
        $settings = $settingsJson ? json_decode($settingsJson, true) : [];
        $settings['lastbirthdayappeal'] = now()->format('Y-m-d H:i:s');

        DB::table('users')
            ->where('id', $userId)
            ->update(['settings' => json_encode($settings)]);
    }
}
