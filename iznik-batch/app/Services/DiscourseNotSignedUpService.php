<?php

namespace App\Services;

use App\Mail\Housekeeper\DiscourseReportMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Weekly check for active moderators who haven't signed up to Discourse, plus
 * TrashNothing accounts holding a mod role. Migrated from V1
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
            ->whereIn('users.systemrole', [User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_SUPPORT, User::SYSTEMROLE_ADMIN])
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

        // Resolve every Discourse user's MT external_id.
        $discourseExternalIds = [];

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
        }

        // TrashNothing accounts must never hold a mod role. They are never on
        // Discourse and are often long inactive, so look for them in the
        // system roles directly rather than among the Discourse users.
        $tnMods = $this->tnAccountsWithModRole();
        foreach ($tnMods as $tnMod) {
            $reportTop .= 'TN ACCOUNT HAS MOD ROLE: '.$tnMod->id.' - '.$tnMod->fullname.' - '.$tnMod->roles."\n";
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

        $reportTop .= 'TN accounts with mod roles: '.count($tnMods)."\n\n";

        $report = $reportTop.$reportMid;
        $report .= "\ndiscourse:not-signed-up — migrated from V1 discourse_not_signed_up.php\n";

        $this->sendReports($notondiscourse, $report);

        return [
            'skipped' => false,
            'notondiscourse' => $notondiscourse,
            'tnmods' => count($tnMods),
        ];
    }

    /**
     * Users stamped with a tnuserid or whose preferred email is a TrashNothing
     * address, holding a moderator, support or admin system role.
     *
     * @return \Illuminate\Support\Collection<int, object{id: int, fullname: ?string, roles: string}>
     */
    private function tnAccountsWithModRole(): \Illuminate\Support\Collection
    {
        return DB::table('users')
            ->whereIn('users.systemrole', [
                User::SYSTEMROLE_MODERATOR,
                User::SYSTEMROLE_SUPPORT,
                User::SYSTEMROLE_ADMIN,
            ])
            ->where(function ($q) {
                $q->whereNotNull('users.tnuserid')
                    ->orWhereExists(function ($e) {
                        $e->from('users_emails')
                            ->whereColumn('users_emails.userid', 'users.id')
                            ->where('users_emails.preferred', 1)
                            ->where('users_emails.email', 'LIKE', '%@user.trashnothing.com');
                    });
            })
            ->orderBy('users.id')
            ->get([
                'users.id',
                'users.fullname',
                'users.systemrole as roles',
            ]);
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
