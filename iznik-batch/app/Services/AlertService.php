<?php

namespace App\Services;

use App\Mail\Alert\AlertMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlertService
{
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
     * Process all incomplete alerts, sending one email to each moderator.
     *
     * Moderation is national, so the recipients are every moderator, support user and
     * admin (users.systemrole). Tracking in alerts_tracking prevents duplicate sends,
     * and the alert is marked complete once everyone has been mailed.
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

        $sent = $this->mailMods($alert, $fromAddr, $fromName, $htmlBody, $dryRun);

        if (!$dryRun) {
            DB::table('alerts')->where('id', $alert->id)->update(['complete' => now()]);
            Log::info('AlertService: completed alert', ['alert_id' => $alert->id]);
        }

        return $sent;
    }

    private function mailMods(object $alert, string $fromAddr, string $fromName, string $htmlBody, bool $dryRun): int
    {
        $modSite = config('freegle.sites.mod', 'https://modtools.org');
        $askClick = (bool) ($alert->askclick ?? false);

        $mods = DB::table('users')
            ->whereIn('systemrole', ['Moderator', 'Support', 'Admin'])
            ->whereNull('deleted')
            ->pluck('id');

        $sent = 0;

        foreach ($mods as $userId) {
            $user = DB::table('users')->where('id', $userId)->first();

            if (!$user) {
                continue;
            }

            // V1 parity: pick the user's preferred external email (skipping
            // our own per-user-alias domains so the mail can't loop back as
            // chat), then find its row id for alerts_tracking.
            $preferredEmail = \App\Models\User::find($userId)?->email_preferred;
            $emails = collect();
            if ($preferredEmail) {
                $row = DB::table('users_emails')
                    ->where('userid', $userId)
                    ->where('email', $preferredEmail)
                    ->whereNull('bounced')
                    ->first(['id', 'email']);
                if ($row) {
                    $emails = collect([$row]);
                }
            }

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

                if ($alreadySent) {
                    continue;
                }

                $trackId = null;
                if (!$dryRun) {
                    $trackId = DB::table('alerts_tracking')->insertGetId([
                        'alertid' => $alert->id,
                        'userid' => $userId,
                        'emailid' => $emailRow->id,
                        'type' => 'ModEmail',
                    ]);

                    $name = $user->fullname
                        ?: trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''))
                        ?: 'Freegle Volunteer';

                    try {
                        app(\App\Services\EmailSpoolerService::class)->spool(new AlertMail(
                            recipientEmail: $email,
                            recipientName: $name,
                            fromAddress: $fromAddr,
                            fromName: $fromName,
                            subjectLine: $alert->subject,
                            htmlBody: $htmlBody,
                            textBody: $alert->text ?? '',
                            trackId: $trackId,
                            askClick: $askClick,
                            global: false,
                            groupName: null,
                            beaconBase: $modSite,
                            recipientUserId: (int) $userId,
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
        $geeks = config('freegle.mail.geeks_addr') ?: 'geeks@ilovefreegle.org';

        if (isset(self::$fromMap[$role])) {
            $entry = self::$fromMap[$role];
            // Use ?: not config()'s default arg: config()'s default applies only
            // when the key is ABSENT, but a role address may be present-but-null
            // (its env var unset → config returns null). Fall back to geeks for
            // both the absent and null cases.
            $addr = isset($entry['config'])
                ? (config($entry['config']) ?: $geeks)
                : $entry['addr'];
            return [$addr, $entry['name']];
        }

        return [$geeks, 'Freegle'];
    }
}
