<?php

namespace App\Services;

use App\Mail\Housekeeper\DiscourseReportMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Weekly check for active moderators who haven't signed up to Discourse, and mods
 * whose preferred email is a TrashNothing address. Migrated from V1
 * scripts/cron/discourse_not_signed_up.php.
 *
 * "Active" mod = a moderator, support user or admin (users.systemrole) with
 * users.lastaccess in the last 6 months. There are no communities to be
 * "represented" on Discourse any more. Reports to geeks (and central mods) on Saturdays.
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

        // Active mods, ordered by user id.
        $sixMonthsAgo = Carbon::now()->subMonths(6);
        $activeModsGroups = DB::table('users')
            ->whereIn('users.systemrole', ['Moderator', 'Support', 'Admin'])
            ->whereNull('users.deleted')
            ->where('users.lastaccess', '>', $sixMonthsAgo)
            ->orderBy('users.id')
            ->get([
                'users.id as id',
                'users.fullname as fullname',
                'users.lastaccess as lastaccess',
            ]);

        $distinctActiveMods = $activeModsGroups->pluck('id')->unique()->count();

        $reportTop = 'Total Discourse users: '.count($allDusers)."\n";
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

        // Active mods not on Discourse.
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
        }

        $reportMid .= "\nActive volunteers not on discourse: $notondiscourse\n\n";

        $reportTop .= "Mods with TN preferred emails: $modswithTNpreferredemails\n\n";

        $report = $reportTop.$reportMid;
        $report .= "\ndiscourse:not-signed-up — migrated from V1 discourse_not_signed_up.php\n";

        $this->sendReports($notondiscourse, $report);

        return [
            'skipped' => false,
            'notondiscourse' => $notondiscourse,
            'tnpreferred' => $modswithTNpreferredemails,
        ];
    }

    private function sendReports(int $notondiscourse, string $report): void
    {
        $from = (string) config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org');
        $geeks = (string) config('freegle.mail.geek_alerts_addr', 'geek-alerts@ilovefreegle.org');
        $centralmods = (string) config('freegle.mail.centralmods_addr');

        $subject = 'Discourse: ';
        if ($notondiscourse > 0) {
            $subject .= "$notondiscourse volunteers not signed up. ";
        }
        if ($notondiscourse === 0) {
            $subject .= 'all active volunteers on here';
        }

        $weeklySendToday = Carbon::now()->dayOfWeek === Carbon::SATURDAY;

        if ($weeklySendToday) {
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
