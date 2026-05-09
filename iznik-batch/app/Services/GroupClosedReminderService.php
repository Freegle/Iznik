<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\UserEmail;
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
        $geeksAddr   = config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org');
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
                Mail::raw(
                    "Hi there - just to remind you that your Freegle group is currently closed. "
                    . "If you now feel it's time to re-open then you can do that from ModTools, "
                    . "in Settings->Community->Features for Members->Closed for COVID-19. "
                    . "We'll send this automated mail once a week.",
                    function ($message) use ($modEmails, $mentorsAddr, $geeksAddr, $group) {
                        $message->to($modEmails)
                            ->cc($mentorsAddr)
                            ->from($geeksAddr, 'Freegle')
                            ->subject('Reminder: Your Freegle group is currently closed');
                    }
                );
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
            ->join('users_emails', 'users_emails.userid', '=', 'memberships.userid')
            ->where('memberships.groupid', $groupId)
            ->whereIn('memberships.role', [Membership::ROLE_OWNER, Membership::ROLE_MODERATOR])
            ->where('memberships.collection', Membership::COLLECTION_APPROVED)
            ->where('users_emails.preferred', 1);

        if ($systemUserId) {
            $query->where('memberships.userid', '!=', $systemUserId);
        }

        return $query->pluck('users_emails.email')->all();
    }
}
