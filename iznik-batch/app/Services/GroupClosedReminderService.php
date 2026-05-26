<?php

namespace App\Services;

use App\Mail\Group\ClosedGroupReminderMail;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends weekly reminders to mods of groups that are currently closed.
 *
 * Migrated from iznik-server/scripts/cron/groups_closed.php
 */
class GroupClosedReminderService
{
    private const SYSTEM_MOD_EMAIL = 'modtools@modtools.org';

    public function sendReminders(bool $dryRun = false): array
    {
        $mentorsAddr = config('freegle.mail.mentors_addr', 'mentors@ilovefreegle.org');

        $closedGroups = DB::table('groups')
            ->whereNotNull('settings')
            ->whereRaw("JSON_EXTRACT(settings, '$.closed') = 1")
            ->select(['id', 'nameshort'])
            ->get();

        $sent    = 0;
        $skipped = 0;

        foreach ($closedGroups as $group) {
            $modEmails = $this->getModEmails($group->id);

            if (empty($modEmails)) {
                Log::info("GroupClosedReminder: no mod emails for group {$group->id} {$group->nameshort}");
                $skipped++;
                continue;
            }

            Log::info("GroupClosedReminder: reminding mods of closed group {$group->nameshort}", [
                'group_id' => $group->id,
                'mod_count' => count($modEmails),
                'dry_run'   => $dryRun,
            ]);

            if (!$dryRun) {
                foreach ($modEmails as $modEmail) {
                    Mail::send(new ClosedGroupReminderMail($modEmail, $group->nameshort));
                }
                Mail::send(new ClosedGroupReminderMail($mentorsAddr, $group->nameshort));
            }

            $sent++;
        }

        return [
            'groups_checked' => count($closedGroups),
            'sent'           => $sent,
            'skipped'        => $skipped,
        ];
    }

    /**
     * @return string[]  Preferred email addresses for all active mods/owners of this group.
     */
    private function getModEmails(int $groupId): array
    {
        $systemUserId = DB::table('users_emails')
            ->where('email', self::SYSTEM_MOD_EMAIL)
            ->value('userid');

        $query = DB::table('memberships')
            ->where('memberships.groupid', $groupId)
            ->whereIn('memberships.role', [Membership::ROLE_OWNER, Membership::ROLE_MODERATOR])
            ->where('memberships.collection', Membership::COLLECTION_APPROVED);

        if ($systemUserId) {
            $query->where('memberships.userid', '!=', $systemUserId);
        }

        // V1 parity: pick each mod's preferred external email; skip mods who
        // only have internal-alias addresses.
        $emails = [];
        foreach ($query->pluck('memberships.userid') as $modId) {
            $email = \App\Models\User::find($modId)?->email_preferred;
            if ($email) {
                $emails[] = $email;
            }
        }
        return $emails;
    }
}
