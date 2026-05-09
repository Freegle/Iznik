<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ModWelfareService
{
    // Days without approval before a mod is considered inactive
    public const ACTIVE_LIMIT_DAYS = 365;

    // Days before we re-warn about the same mod/group combination
    public const REWARN_INTERVAL_DAYS = 31;

    /**
     * Check for inactive mods and notify group owners/mentors.
     *
     * @return array{inactive_mods: int, skipped: int}
     */
    public function checkInactiveMods(bool $dryRun = false): array
    {
        $mentorsAddr = config('freegle.mail.mentors_addr');
        $modbotEmail = config('freegle.mail.moderator_email');
        $supportAddr = config('freegle.mail.support_addr');
        $geeksAddr = config('freegle.mail.geeks_addr');

        $groups = DB::table('groups')
            ->where('type', 'Freegle')
            ->where('onhere', 1)
            ->where('publish', 1)
            ->orderBy('nameshort')
            ->select(['id', 'nameshort', 'namefull', 'contactmail'])
            ->get();

        $inactiveMods = 0;
        $skipped = 0;

        foreach ($groups as $group) {
            $groupName = $group->namefull ?: $group->nameshort;

            // Check if group has any recent approval activity
            $recentApproval = DB::table('messages_groups')
                ->where('groupid', $group->id)
                ->whereNotNull('approvedby')
                ->where('arrival', '>=', now()->subDays(self::ACTIVE_LIMIT_DAYS)->toDateString())
                ->exists();

            if (!$recentApproval) {
                continue;
            }

            // Check if group has an active owner — if not, notify mentors
            $notifyAddrs = $this->getModsToNotify($group->id, false);
            if ($notifyAddrs === $mentorsAddr) {
                $subject = $groupName . ' does not have an active owner';
                $body = "We don't think there is an active mod with owner status on this group. Can you check it?";
                if (!$dryRun) {
                    Mail::raw($body, function ($message) use ($subject, $supportAddr, $mentorsAddr) {
                        $message->from($supportAddr, 'Freegle')
                            ->to($mentorsAddr)
                            ->subject($subject);
                    });
                }
                continue;
            }

            // Get mods added within the last ACTIVE_LIMIT_DAYS
            $addedSince = now()->subDays(self::ACTIVE_LIMIT_DAYS)->toDateString();
            $mods = DB::table('memberships')
                ->join('users', 'users.id', '=', 'memberships.userid')
                ->where('memberships.groupid', $group->id)
                ->whereIn('memberships.role', ['Moderator', 'Owner'])
                ->where('memberships.added', '>=', $addedSince)
                ->whereNull('users.deleted')
                ->select(['users.id', 'users.email', 'memberships.settings'])
                ->get();

            foreach ($mods as $mod) {
                // Skip modbot user
                $modEmail = $this->getPreferredEmail($mod->id);
                if ($modEmail === $modbotEmail) {
                    $skipped++;
                    continue;
                }

                // Skip if already warned recently
                $recentlyWarned = DB::table('groups_mods_welfare')
                    ->where('modid', $mod->id)
                    ->where(function ($q) use ($group) {
                        $q->whereNull('groupid')->orWhere('groupid', $group->id);
                    })
                    ->where(function ($q) {
                        $q->where('state', 'Ignore')
                            ->orWhere('warnedat', '>=', now()->subDays(self::REWARN_INTERVAL_DAYS));
                    })
                    ->exists();

                if ($recentlyWarned) {
                    $skipped++;
                    continue;
                }

                // Check if this mod should be active
                if (!$this->isActiveMod($mod->id, $group->id, $mod->settings)) {
                    $skipped++;
                    continue;
                }

                // Check last approval activity
                $approvalData = DB::table('messages_groups')
                    ->where('approvedby', $mod->id)
                    ->selectRaw('COUNT(*) AS acted, DATEDIFF(NOW(), MAX(arrival)) AS activeago')
                    ->first();

                $acted = (int) ($approvalData->acted ?? 0);
                $lastActive = $approvalData->activeago ?? null;

                if (!$acted || $lastActive > self::ACTIVE_LIMIT_DAYS) {
                    $inactiveMods++;

                    $modName = $this->getModName($mod->id);
                    $daysAgo = $lastActive ? "{$lastActive} days" : 'a long time';

                    $subject = "{$groupName}: {$modName} doesn't seem to be active";
                    $body = "Please see https://discourse.ilovefreegle.org/t/safeguarding-volunteers for the background, and post any questions/issues on there.\r\n\r\n"
                        . "We are identifying volunteers on who have not actively moderated for some time, and contacting you about them because we believe you are responsible for the group.\r\n\r\n"
                        . "Initially, some of these may have been inactive for a long time. We will gradually decrease the timescale so that it will find volunteers who are usually active, but have stopped, so that you can check that they are OK.\r\n\r\n"
                        . "We think {$modName} ({$modEmail}) has not been actively moderating on {$groupName} for {$daysAgo}.\r\n\r\n"
                        . "At this point there are several things you could do:\r\n"
                        . "1. Contact them and see if they'd like to stay active, or come back.\r\n"
                        . "2. If you know that they are a backup mod, contact them and ask them to change their ModTools settings in Settings->Community->Your Settings to record them as a backup.\r\n"
                        . "3. If you know that they are no longer a mod, or it's a duplicate account, please manually change their status to Member, from Members->Approved.\r\n"
                        . "4. Do nothing. Eventually the change to member status may happen automatically after about six months, but we'll be discussing how that works later.\r\n"
                        . "5. If there is some reason why you'd like them to be exempt from this check (e.g. if they are long-term ill) please forward this mail to {$geeksAddr}.\r\n\r\n"
                        . "We will notify you about the same volunteer no more than once a month.\r\n\r\n"
                        . "Regards,\r\n\r\nFreegle Geeks";

                    if (!$dryRun) {
                        // Notify the relevant owners/mods via notifyAboutSignificantEvent equivalent
                        $this->notifyAboutSignificantEvent($group->id, $subject, $body, $supportAddr, $mentorsAddr);

                        DB::table('groups_mods_welfare')->upsert(
                            ['groupid' => $group->id, 'modid' => $mod->id, 'warnedat' => now()],
                            ['groupid', 'modid'],
                            ['warnedat']
                        );
                    }
                }
            }
        }

        return ['inactive_mods' => $inactiveMods, 'skipped' => $skipped];
    }

    /**
     * Get addresses to notify about significant events for a group.
     * Returns MENTORS_ADDR string if no active owners found (fallback).
     *
     * @return string|array
     */
    private function getModsToNotify(int $groupId, bool $allowMods = true): string|array
    {
        $mentorsAddr = config('freegle.mail.mentors_addr');
        $ret = [];

        // Try owners first — active (settings.active = true), approved within 31 days
        $owners = DB::table('memberships')
            ->join('users', 'users.id', '=', 'memberships.userid')
            ->where('memberships.groupid', $groupId)
            ->where('memberships.role', 'Owner')
            ->whereNull('users.deleted')
            ->select(['users.id', 'memberships.settings'])
            ->get();

        foreach ($owners as $owner) {
            if ($this->getLastApprovalDays($owner->id, $groupId) <= 31
                && $this->isActiveMod($owner->id, $groupId, $owner->settings)) {
                $ret[] = $owner->id;
            }
        }

        // Fallback: backup owners (active=false in settings)
        if (empty($ret)) {
            foreach ($owners as $owner) {
                if ($this->getLastApprovalDays($owner->id, $groupId) <= 31
                    && !$this->isActiveMod($owner->id, $groupId, $owner->settings)) {
                    $ret[] = $owner->id;
                }
            }
        }

        // Fallback: active mods
        if (empty($ret) && $allowMods) {
            $mods = DB::table('memberships')
                ->join('users', 'users.id', '=', 'memberships.userid')
                ->where('memberships.groupid', $groupId)
                ->where('memberships.role', 'Moderator')
                ->whereNull('users.deleted')
                ->select(['users.id', 'memberships.settings'])
                ->get();

            foreach ($mods as $mod) {
                if ($this->getLastApprovalDays($mod->id, $groupId) <= 31
                    && $this->isActiveMod($mod->id, $groupId, $mod->settings)) {
                    $ret[] = $mod->id;
                }
            }

            // Fallback: backup mods
            if (empty($ret)) {
                foreach ($mods as $mod) {
                    if ($this->getLastApprovalDays($mod->id, $groupId) <= 31
                        && !$this->isActiveMod($mod->id, $groupId, $mod->settings)) {
                        $ret[] = $mod->id;
                    }
                }
            }
        }

        return empty($ret) ? $mentorsAddr : $ret;
    }

    private function getLastApprovalDays(int $userId, int $groupId): int
    {
        $row = DB::table('messages_groups')
            ->where('approvedby', $userId)
            ->where('groupid', $groupId)
            ->selectRaw('COUNT(*) AS count, DATEDIFF(NOW(), MAX(arrival)) AS ago')
            ->first();

        return ($row && $row->count > 0) ? (int) $row->ago : PHP_INT_MAX;
    }

    private function isActiveMod(int $userId, int $groupId, ?string $settingsJson): bool
    {
        $settings = $settingsJson ? json_decode($settingsJson, true) : [];
        if (isset($settings['active'])) {
            return (bool) $settings['active'];
        }
        // Legacy fallback: showmessages
        if (isset($settings['showmessages'])) {
            return (bool) $settings['showmessages'];
        }
        return true;
    }

    private function notifyAboutSignificantEvent(int $groupId, string $subject, string $body, string $fromAddr, string $mentorsAddr): void
    {
        $notify = $this->getModsToNotify($groupId);

        if (is_string($notify)) {
            // Fallback: notify mentors directly
            Mail::raw($body, function ($message) use ($subject, $fromAddr, $notify) {
                $message->from($fromAddr, 'Freegle')->to($notify)->subject($subject);
            });
            return;
        }

        foreach ($notify as $userId) {
            $email = $this->getPreferredEmail($userId);
            if ($email) {
                Mail::raw($body, function ($message) use ($subject, $fromAddr, $email) {
                    $message->from($fromAddr, 'Freegle')->to($email)->subject($subject);
                });
            }
        }
    }

    private function getPreferredEmail(int $userId): ?string
    {
        $internalDomains = config('freegle.mail.internal_domains', []);
        $emails = DB::table('users_emails')
            ->where('userid', $userId)
            ->orderByDesc('preferred')
            ->pluck('email');

        foreach ($emails as $email) {
            $domain = substr(strrchr($email, '@'), 1);
            if (!in_array($domain, $internalDomains)) {
                return $email;
            }
        }
        return null;
    }

    private function getModName(int $userId): string
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            return 'Unknown';
        }
        if ($user->fullname) {
            return $user->fullname;
        }
        return trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')) ?: 'Freegle User';
    }
}
