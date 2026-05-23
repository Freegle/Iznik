<?php

namespace App\Services;

use App\Mail\Alert\AlertMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AlertService
{
    private const GROUP_BATCH_SIZE = 50;

    private static array $fromMap = [
        'support' => ['config' => 'freegle.mail.support_addr', 'name' => 'Freegle Support'],
        'info' => ['config' => 'freegle.mail.info_addr', 'name' => 'Freegle Info'],
        'geeks' => ['config' => 'freegle.mail.geeks_addr', 'name' => 'Freegle Geeks'],
        'mentors' => ['config' => 'freegle.mail.mentors_addr', 'name' => 'Freegle Mentors'],
        'board' => ['addr' => 'board@ilovefreegle.org', 'name' => 'Freegle Board'],
        'chair' => ['addr' => 'chair@ilovefreegle.org', 'name' => 'Freegle Chair'],
        'newgroups' => ['addr' => 'newgroups@ilovefreegle.org', 'name' => 'Freegle New Groups'],
        'ro' => ['addr' => 'ro@ilovefreegle.org', 'name' => 'Freegle Returning Officer'],
        'volunteers' => ['addr' => 'volunteers@ilovefreegle.org', 'name' => 'Freegle Volunteers'],
        'centralmods' => ['addr' => 'centralmods@ilovefreegle.org', 'name' => 'Freegle Volunteer Support'],
        'councils' => ['addr' => 'councils@ilovefreegle.org', 'name' => 'Freegle Partnerships'],
    ];

    /**
     * Process all incomplete alerts, sending emails to mods of each group in batches.
     *
     * Mirrors V1 cron/alerts.php (Alert::process() + mailMods()):
     * - Picks up incomplete alerts and processes the next batch of 50 groups.
     * - Each mod gets one email per alert regardless of how many groups they moderate.
     * - Tracking in alerts_tracking prevents duplicate sends.
     * - Alert is marked complete once all groups are processed.
     *
     * @return int Total emails sent.
     */
    public function processAlerts(bool $dryRun = false): int
    {
        $alerts = DB::table('alerts')
            ->whereNull('complete')
            ->get();

        $totalSent = 0;

        foreach ($alerts as $alert) {
            $totalSent += $this->processAlert($alert, $dryRun);
        }

        return $totalSent;
    }

    private function processAlert(object $alert, bool $dryRun): int
    {
        [$fromAddr, $fromName] = $this->resolveFrom($alert->from);
        $htmlBody = $alert->html ?: nl2br(e($alert->text));

        $groups = DB::table('groups')
            ->where('type', 'Freegle')
            ->where('id', '>', $alert->groupprogress)
            ->where('publish', 1)
            ->orderBy('id')
            ->limit(self::GROUP_BATCH_SIZE)
            ->get(['id', 'nameshort', 'contactmail']);

        // Fewer than batch size means this is the last batch — mark complete after processing.
        $complete = count($groups) < self::GROUP_BATCH_SIZE;
        $sent = 0;

        foreach ($groups as $group) {
            $sent += $this->mailGroupMods($alert, $group, $fromAddr, $fromName, $htmlBody, $dryRun);

            if (!$dryRun) {
                DB::table('alerts')->where('id', $alert->id)->update(['groupprogress' => $group->id]);
            }
        }

        if ($complete && !$dryRun) {
            DB::table('alerts')->where('id', $alert->id)->update(['complete' => now()]);
            Log::info('AlertService: completed alert', ['alert_id' => $alert->id]);
        }

        return $sent;
    }

    private function mailGroupMods(object $alert, object $group, string $fromAddr, string $fromName, string $htmlBody, bool $dryRun): int
    {
        $mods = DB::table('memberships')
            ->where('groupid', $group->id)
            ->whereIn('role', ['Owner', 'Moderator'])
            ->pluck('userid');

        $sent = 0;

        foreach ($mods as $userId) {
            $user = DB::table('users')
                ->where('id', $userId)
                ->whereNull('deleted')
                ->first();

            if (!$user) {
                continue;
            }

            $emails = DB::table('users_emails')
                ->where('userid', $userId)
                ->whereNull('bounced')
                ->where('preferred', 1)
                ->get(['id', 'email']);

            foreach ($emails as $emailRow) {
                $email = $emailRow->email;

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $alreadySent = DB::table('alerts_tracking')
                    ->where('alertid', $alert->id)
                    ->where('userid', $userId)
                    ->where('emailid', $emailRow->id)
                    ->exists();

                if (!$dryRun) {
                    DB::table('alerts_tracking')->insert([
                        'alertid' => $alert->id,
                        'groupid' => $group->id,
                        'userid' => $userId,
                        'emailid' => $emailRow->id,
                        'type' => 'ModEmail',
                    ]);
                }

                if ($alreadySent) {
                    continue;
                }

                if (!$dryRun) {
                    $name = $user->fullname
                        ?: trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''))
                        ?: 'Freegle Volunteer';

                    try {
                        Mail::send(new AlertMail(
                            recipientEmail: $email,
                            recipientName: $name,
                            fromAddress: $fromAddr,
                            fromName: $fromName,
                            subject: $alert->subject,
                            htmlBody: $htmlBody,
                            textBody: $alert->text ?? '',
                        ));
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::error('AlertService: failed to send alert email', [
                            'alert_id' => $alert->id,
                            'user_id' => $userId,
                            'email' => $email,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $sent;
    }

    private function resolveFrom(string $role): array
    {
        if (isset(self::$fromMap[$role])) {
            $entry = self::$fromMap[$role];
            $addr = isset($entry['config'])
                ? config($entry['config'], config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org'))
                : $entry['addr'];
            return [$addr, $entry['name']];
        }

        return [
            config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org'),
            'Freegle',
        ];
    }
}
