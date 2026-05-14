<?php

namespace App\Services;

use App\Mail\Group\CustomisationReminderMail;
use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends monthly reminders to group moderators about missing customisation attributes.
 *
 * Migrated from iznik-server/scripts/cron/group_customisation.php
 */
class GroupCustomisationService
{
    private const SYSTEM_MOD_EMAIL = 'modtools@modtools.org';

    private const CUST_ATTRS = [
        'profile'     => '* Profile picture - this helps people recognise your group.',
        'tagline'     => '* Tagline - something short and snappy, ideally with a local reference.',
        'welcomemail' => '* Welcome Mail - this is sent out to people when they join - your chance to welcome them, and let them know anything they need to (e.g. whether pets are allowed).',
        'description' => '* Description - this is longer than the tagline, and is visible on the site - often containing similar information to the welcome mail.',
    ];

    public function sendReminders(bool $dryRun = false): array
    {
        $noreplyAddr = config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org');
        $mentorsAddr = config('freegle.mail.mentors_addr', 'mentors@ilovefreegle.org');
        $modSite     = config('freegle.sites.mod', 'https://modtools.org');

        $groups = DB::table('groups')
            ->where('type', Group::TYPE_FREEGLE)
            ->where('publish', 1)
            ->where('onhere', 1)
            ->orderByRaw('RAND()')
            ->select(['id', 'nameshort', 'profile', 'tagline', 'welcomemail', 'description'])
            ->get();

        $sent    = 0;
        $skipped = 0;

        foreach ($groups as $group) {
            $missing = $this->buildMissingList($group);

            if ($missing === '') {
                $skipped++;
                continue;
            }

            $modEmails = $this->getModEmails($group->id);

            if (empty($modEmails)) {
                Log::info("GroupCustomisation: no mod emails for group {$group->id} {$group->nameshort}");
                $skipped++;
                continue;
            }

            $body = "Just to remind you, you can make your Freegle group look more local and friendly for your members by customising how it appears on Freegle Direct.\n\n"
                . "Here are some things you could add:\n\n"
                . $missing . "\n"
                . "You can change these from ModTools ({$modSite}), in Settings - Group Settings - Group Appearance. ModTools helps you run your group, and is free to use for all Freegle groups.\n\n"
                . "If you need help with this, then please contact {$mentorsAddr}\n\n"
                . "P.S. This is an automated message sent once a month. We send it regularly because sometimes the moderators on a group change, and they might not know about this stuff.";

            Log::info("GroupCustomisation: sending reminder for {$group->nameshort}", [
                'group_id' => $group->id,
                'mod_count' => count($modEmails),
                'dry_run'   => $dryRun,
            ]);

            if (!$dryRun) {
                foreach ($modEmails as $modEmail) {
                    Mail::send(new CustomisationReminderMail($modEmail, $group->nameshort, $missing));
                }
                $mentorsAddr = config('freegle.mail.mentors_addr', 'mentors@ilovefreegle.org');
                if ($mentorsAddr) {
                    Mail::send(new CustomisationReminderMail($mentorsAddr, $group->nameshort, $missing));
                }
            }

            $sent++;
        }

        return [
            'groups_checked' => count($groups),
            'sent'           => $sent,
            'skipped'        => $skipped,
        ];
    }

    private function buildMissingList(object $group): string
    {
        $missing = '';

        foreach (self::CUST_ATTRS as $attr => $description) {
            if (empty($group->$attr)) {
                $missing .= $description . "\n";
            }
        }

        return $missing;
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
