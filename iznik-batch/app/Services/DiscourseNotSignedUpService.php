<?php

namespace App\Services;

use App\Mail\Housekeeper\DiscourseReportMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily check for Freegle groups not represented by an active moderator on
 * Discourse, plus active mods who haven't signed up and mods whose preferred
 * email is a TrashNothing address. Faithful migration of V1
 * scripts/cron/discourse_not_signed_up.php.
 *
 * "Active" mod = Owner/Moderator on a Freegle group with users.lastaccess in
 * the last 6 months. Reports daily to geeks (and central mods) on Saturdays or
 * whenever a group is unrepresented.
 */
class DiscourseNotSignedUpService
{
    public function __construct(private DiscourseClient $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        if (!$this->client->isConfigured()) {
            Log::warning('DiscourseNotSignedUpService: Discourse API key not configured — skipping.');

            return ['skipped' => true];
        }

        $throttleUs = (int) config('freegle.discourse.throttle_us', 250000);

        $allDusers = $this->client->getAllUsers();

        // Published groups, all initially "not represented".
        $allGroups = DB::table('groups')
            ->where('publish', 1)
            ->orderBy('nameshort')
            ->get(['id', 'nameshort']);
        $represented = [];
        foreach ($allGroups as $g) {
            $represented[$g->id] = false;
        }

        // Active mods (one row per user+group they moderate), ordered by user id.
        $sixMonthsAgo = Carbon::now()->subMonths(6);
        $activeModsGroups = DB::table('users')
            ->join('memberships', 'users.id', '=', 'memberships.userid')
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->whereIn('memberships.role', ['Owner', 'Moderator'])
            ->where('groups.type', 'Freegle')
            ->where('users.lastaccess', '>', $sixMonthsAgo)
            ->orderBy('users.id')
            ->get([
                'users.id as id',
                'users.fullname as fullname',
                'users.lastaccess as lastaccess',
                'groups.id as groupid',
                'memberships.settings as settings',
            ]);

        $distinctActiveMods = $activeModsGroups->pluck('id')->unique()->count();

        $reportTop = 'Total Discourse users: '.count($allDusers)."\n";
        $reportTop .= 'Published groups: '.count($allGroups)."\n";
        $reportTop .= 'Total active mods: '.$distinctActiveMods." (in last 6 months)\n";
        $reportMid = "\nList of these volunteers not on Discourse:\n";

        $activeModIds = $activeModsGroups->pluck('id')->map(fn ($v) => (int) $v)->unique()->all();

        // Resolve every Discourse user's MT external_id and flag TN preferred mails.
        $discourseExternalIds = [];
        $modswithTNpreferredemails = 0;

        foreach ($allDusers as $duser) {
            $this->throttle($throttleUs);

            $username = (string) ($duser['username'] ?? '');
            $fulluser = $this->client->getUser((int) ($duser['id'] ?? 0), $username);

            $externalId = null;
            if (isset($fulluser['single_sign_on_record']) && is_array($fulluser['single_sign_on_record'])) {
                $externalId = $fulluser['single_sign_on_record']['external_id'] ?? null;
            }

            if ($externalId === null || $externalId === false || $externalId === '') {
                continue;
            }
            $externalId = (int) $externalId;

            // If the SSO external_id isn't a current active mod (e.g. stale after
            // a merge), fall back to resolving by the Discourse email.
            if (!in_array($externalId, $activeModIds, true)) {
                $demail = $this->client->getUserEmail($username);
                if ($demail !== '') {
                    $byEmail = DB::table('users_emails')->where('email', $demail)->value('userid');
                    if ($byEmail) {
                        $externalId = (int) $byEmail;
                    }
                }
            }

            $discourseExternalIds[$externalId] = true;

            // Mods whose preferred email is a TrashNothing address.
            $tn = DB::table('users_emails')
                ->where('userid', $externalId)
                ->where('preferred', 1)
                ->where('email', 'LIKE', '%@user.trashnothing.com')
                ->value('email');
            if ($tn) {
                $reportTop .= 'MOD HAS TN preferred email: '.$externalId.' - '.$tn."\n";
                $modswithTNpreferredemails++;
            }
        }

        // Active mods not on Discourse, and which groups are represented.
        $notondiscourse = 0;
        $lastmodid = 0;
        foreach ($activeModsGroups as $mod) {
            $modId = (int) $mod->id;
            $modfirstseen = $lastmodid !== $modId;
            $lastmodid = $modId;

            $found = isset($discourseExternalIds[$modId]);

            if ($modfirstseen && !$found) {
                $reportMid .= '* '.$modId.': '.$mod->fullname.' - '.$mod->lastaccess."\n";
                $notondiscourse++;
            }

            if ($found) {
                // A mod with this group muted (active:0) doesn't count as cover.
                if ($mod->settings && str_contains($mod->settings, '"active":0')) {
                    continue;
                }
                $represented[$mod->groupid] = true;
            }
        }

        $reportMid .= "\nActive volunteers not on discourse: $notondiscourse\n\n";

        // Groups with no active mod represented on Discourse.
        $notrepresentedcount = 0;
        foreach ($allGroups as $group) {
            if (!empty($represented[$group->id])) {
                continue;
            }

            $reportTop .= 'NOT REPRESENTED '.$group->id.' - '.$group->nameshort."\n";
            foreach ($activeModsGroups as $mod) {
                if ((int) $mod->groupid === (int) $group->id) {
                    $reportTop .= '* Moderator: '.$mod->id.' - '.$mod->fullname."\n";
                }
            }
            $notrepresentedcount++;
        }

        $reportTop .= "\nGroups without active volunteers on Discourse: $notrepresentedcount\n";
        $reportTop .= "Mods with TN preferred emails: $modswithTNpreferredemails\n\n";

        $report = $reportTop.$reportMid;
        $report .= "\ndiscourse:not-signed-up — migrated from V1 discourse_not_signed_up.php\n";

        $this->sendReports($notrepresentedcount, $notondiscourse, $report);

        return [
            'skipped' => false,
            'notrepresented' => $notrepresentedcount,
            'notondiscourse' => $notondiscourse,
            'tnpreferred' => $modswithTNpreferredemails,
        ];
    }

    private function sendReports(int $notrepresented, int $notondiscourse, string $report): void
    {
        $from = (string) config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org');
        $geeks = (string) config('freegle.mail.geek_alerts_addr', 'geek-alerts@ilovefreegle.org');
        $centralmods = (string) config('freegle.mail.centralmods_addr');

        $subject = 'Discourse: ';
        if ($notrepresented === 0) {
            $subject .= 'All groups represented. ';
        } else {
            $subject .= "$notrepresented groups not represented. ";
        }
        if ($notondiscourse > 0) {
            $subject .= "$notondiscourse volunteers not signed up. ";
        }
        if ($notrepresented === 0 && $notondiscourse === 0) {
            $subject .= 'all active volunteers on here';
        }

        $weeklySendToday = Carbon::now()->dayOfWeek === Carbon::SATURDAY;

        if ($weeklySendToday || $notrepresented > 0) {
            $this->send($centralmods, 'Volunteer Support', $from, $subject, $report);
            $this->send($geeks, 'Geeks Alerts', $from, $subject, $report);
        }
    }

    private function send(string $to, string $toName, string $from, string $subject, string $body): void
    {
        if ($to === '') {
            return;
        }

        try {
            app(EmailSpoolerService::class)->spool(
                new DiscourseReportMail($to, $toName, $from, $subject, $body)
            );
        } catch (\Throwable $e) {
            Log::error('DiscourseNotSignedUpService: mail failed', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }

    private function throttle(int $us): void
    {
        if ($us > 0) {
            usleep($us);
        }
    }
}
