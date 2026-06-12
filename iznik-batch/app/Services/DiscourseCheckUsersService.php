<?php

namespace App\Services;

use App\Mail\Housekeeper\DiscourseReportMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily Discourse user audit. Faithful migration of V1
 * scripts/cron/discourse_checkusers.php.
 *
 * Walks every Discourse user and, for those linked to an MT account
 * (single_sign_on_record.external_id), checks they still moderate a group,
 * ensures they watch the Announcements category, keeps their bio in sync with
 * the groups they moderate, and reports bounces / mail settings. A summary is
 * emailed to geeks daily and to central mods when there's something to check
 * (or on Saturdays).
 */
class DiscourseCheckUsersService
{
    private const SYSTEM_USERNAME = 'freeglegeeks';

    public function __construct(private DiscourseClient $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        if (!$this->client->isConfigured()) {
            Log::warning('DiscourseCheckUsersService: Discourse API key not configured — skipping.');

            return ['skipped' => true];
        }

        $announcementsId = (int) config('freegle.discourse.announcements_category_id', 7);
        $throttleUs = (int) config('freegle.discourse.throttle_us', 250000);

        $allusers = $this->client->getAllUsers();
        $now = Carbon::now();

        $c = array_fill_keys([
            'ismod', 'notmod', 'notuser', 'system', 'everposted', 'postedinlastweek',
            'everseen', 'seeninlastweek', 'evermailed', 'mailedinlastweek', 'anybounces',
            'bouncestopped', 'mailinglistmode', 'watchingcount', 'email_digests',
            'notonannouncements', 'notreceivingmail', 'bioupdatedcount',
        ], 0);
        $anybouncers = '';
        $bouncestoppers = '';
        $report = 'Total Discourse users: '.count($allusers)."\n";

        foreach ($allusers as $duser) {
            $username = (string) ($duser['username'] ?? '');

            if ($username === self::SYSTEM_USERNAME) {
                $c['system']++;
                continue;
            }

            $this->throttle($throttleUs);

            if (!empty($duser['last_posted_at'])) {
                $c['everposted']++;
                if ($this->daysSince($duser['last_posted_at'], $now) < 8) {
                    $c['postedinlastweek']++;
                }
            }
            if (!empty($duser['last_seen_at'])) {
                $c['everseen']++;
                if ($this->daysSince($duser['last_seen_at'], $now) < 8) {
                    $c['seeninlastweek']++;
                }
            }

            $fulluser = $this->client->getUser((int) $duser['id'], $username);

            $bounceScore = (float) ($fulluser['bounce_score'] ?? 0);
            if ($bounceScore >= 400) {
                $c['bouncestopped']++;
                $bouncestoppers .= $username.'-'.$bounceScore.', ';
            } elseif ($bounceScore > 0) {
                $c['anybounces']++;
                $anybouncers .= $username.'-'.$bounceScore.', ';
            }

            $externalId = null;
            if (isset($fulluser['single_sign_on_record']) && is_array($fulluser['single_sign_on_record'])) {
                $externalId = $fulluser['single_sign_on_record']['external_id'] ?? null;
            }

            if (!empty($fulluser['last_emailed_at'])) {
                $c['evermailed']++;
                if ($this->daysSince($fulluser['last_emailed_at'], $now) < 8) {
                    $c['mailedinlastweek']++;
                }
            }

            if (!$externalId) {
                continue;
            }

            $externalId = (int) $externalId;
            $useremail = $this->client->getUserEmail($username);
            $mtUserExists = DB::table('users')->where('id', $externalId)->exists();
            $modUserId = $this->resolveModUserId($externalId, $useremail);

            if (!$mtUserExists) {
                // V1 intended this "NOT EVEN A USER" branch but its `new User()`
                // was always truthy, making it dead code; we honour the intent.
                $c['notuser']++;
                $report .= 'Not a MT user: Discourse username: '.$username.', email: '.$useremail."\n";
            } elseif ($modUserId !== null) {
                $c['ismod']++;
            } else {
                $c['notmod']++;
                $report .= 'NOT A MOD. MT id: '.$externalId.', Discourse username: '.$username.', email: '.$useremail."\n";
            }

            // What mail are they getting?
            $this->throttle($throttleUs);
            $public = $this->client->getUserPublic($username);

            $watched = (array) ($public['watched_category_ids'] ?? []);
            $watchedFirst = (array) ($public['watched_first_post_category_ids'] ?? []);
            $tracked = (array) ($public['tracked_category_ids'] ?? []);

            $gettingAnyMails = (count($watched) + count($watchedFirst) + count($tracked)) > 0;
            if ($gettingAnyMails) {
                $c['watchingcount']++;
            }

            $mlm = false;
            if (isset($public['user_option']) && is_array($public['user_option'])) {
                if (!empty($public['user_option']['mailing_list_mode'])) {
                    $mlm = true;
                    $c['mailinglistmode']++;
                    $gettingAnyMails = true;
                }
                if (!empty($public['user_option']['email_digests'])) {
                    $c['email_digests']++;
                    $gettingAnyMails = true;
                }
            }

            if (!$gettingAnyMails) {
                $report .= 'Not getting any mails: Discourse username: '.$username.', email: '.$useremail."\n";
                $c['notreceivingmail']++;
            } elseif (!$mlm) {
                if (!in_array($announcementsId, $watched) &&
                    !in_array($announcementsId, $watchedFirst) &&
                    !in_array($announcementsId, $tracked)) {
                    $c['notonannouncements']++;
                    $this->client->setWatchCategory($username, $watched, $announcementsId);
                    $report .= 'Was not on Announcements: Discourse username: '.$username."\n";
                }
            }

            // Keep the bio in sync with the groups they moderate.
            if ($mtUserExists) {
                $bio = $useremail.' is a mod on '.$this->modGroupList($externalId);
                if ($bio !== ($public['bio_raw'] ?? null)) {
                    $this->client->setBio($username, $bio);
                    $c['bioupdatedcount']++;
                }
            }
        }

        $report .= $this->summary($c, $anybouncers, $bouncestoppers);
        $report .= "\ndiscourse:check-users — migrated from V1 discourse_checkusers.php\n";

        $this->sendReports($c, $report);

        return array_merge(['skipped' => false], $c);
    }

    /**
     * Resolve the MT user id (the user themselves or a merged main account)
     * that moderates a group, or null if none. Mirrors V1's email-based merge
     * fallback.
     */
    private function resolveModUserId(int $externalId, string $useremail): ?int
    {
        if ($this->isModerator($externalId)) {
            return $externalId;
        }

        if ($useremail !== '') {
            $actualId = (int) DB::table('users_emails')
                ->where('email', $useremail)
                ->orderByDesc('id')
                ->value('userid');

            if ($actualId !== 0 && $actualId !== $externalId && $this->isModerator($actualId)) {
                return $actualId;
            }
        }

        return null;
    }

    private function isModerator(int $uid): bool
    {
        return DB::table('memberships')
            ->where('userid', $uid)
            ->whereIn('role', ['Moderator', 'Owner'])
            ->exists();
    }

    private function modGroupList(int $uid): string
    {
        $names = DB::table('memberships')
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $uid)
            ->whereIn('memberships.role', ['Moderator', 'Owner'])
            ->orderBy('groups.nameshort')
            ->selectRaw('COALESCE(groups.namefull, groups.nameshort) AS nm')
            ->pluck('nm')
            ->all();

        return substr(implode(', ', $names), 0, 1000);
    }

    private function summary(array $c, string $anybouncers, string $bouncestoppers): string
    {
        $s = "\n";
        $s .= "ismod: {$c['ismod']}\n";
        $s .= "notmod: {$c['notmod']}\n";
        $s .= "notuser: {$c['notuser']}\n";
        $s .= "system: {$c['system']}\n\n";
        $s .= "everposted: {$c['everposted']}\n";
        $s .= "postedinlastweek: {$c['postedinlastweek']}\n\n";
        $s .= "everseen: {$c['everseen']}\n";
        $s .= "seeninlastweek: {$c['seeninlastweek']}\n\n";
        $s .= "evermailed: {$c['evermailed']}\n";
        $s .= "mailedinlastweek: {$c['mailedinlastweek']}\n\n";
        $s .= "anybounces: {$c['anybounces']} ({$anybouncers})\n";
        $s .= "bouncestopped: {$c['bouncestopped']} ({$bouncestoppers})\n";
        if ($c['bouncestopped'] > 0) {
            $s .= "Check users with stopped mails here: https://discourse.ilovefreegle.org/admin/logs/staff_action_logs - action 'revoke email'\n";
        }
        $s .= "\n";
        $s .= "mailinglistmode: ({$c['mailinglistmode']})\n";
        $s .= "watchingcount: ({$c['watchingcount']})\n";
        $s .= "email_digests: ({$c['email_digests']})\n";
        $s .= "notonannouncements: ({$c['notonannouncements']})";
        $s .= $c['notonannouncements'] > 0 ? " but hopefully now are\n" : "\n";
        $s .= "notreceivingmail: ({$c['notreceivingmail']})\n";
        $s .= "bioupdatedcount: ({$c['bioupdatedcount']})\n";

        return $s;
    }

    private function sendReports(array $c, string $report): void
    {
        $from = (string) config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org');
        $geeks = (string) config('freegle.mail.geek_alerts_addr', 'geek-alerts@ilovefreegle.org');
        $centralmods = (string) config('freegle.mail.centralmods_addr');

        $subject = 'Discourse checkuser OK';
        $mailedCentral = false;

        if ($c['notmod'] || $c['notuser']) {
            $subject = 'Discourse checkuser USERS TO CHECK';
            $this->send($centralmods, 'Volunteer Support', $from, $subject, $report);
            $mailedCentral = true;
        }

        // V1 also mailed central mods on Saturdays even with nothing to check.
        if (!$mailedCentral && Carbon::now()->dayOfWeek === Carbon::SATURDAY) {
            $this->send($centralmods, 'Volunteer Support', $from, $subject, $report);
        }

        $this->send($geeks, 'Geeks Alerts', $from, $subject, $report);
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
            Log::error('DiscourseCheckUsersService: mail failed', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }

    private function daysSince(string $iso, Carbon $now): float
    {
        return abs(Carbon::parse($iso)->diffInDays($now));
    }

    private function throttle(int $us): void
    {
        if ($us > 0) {
            usleep($us);
        }
    }
}
