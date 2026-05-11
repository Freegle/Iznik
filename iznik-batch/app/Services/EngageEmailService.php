<?php

namespace App\Services;

use App\Mail\Engage\EngageMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EngageEmailService
{
    // 182.5 days — matches V1's Engage::USER_INACTIVE = 365*24*60*60/2
    public const USER_INACTIVE_SECONDS = 15768000;

    // How many days before inactive threshold to send the "at risk" warning
    public const AT_RISK_WARNING_DAYS = 7;

    // Minimum days between engagement emails to the same user
    public const RESEND_INTERVAL_DAYS = 7;

    public const ENGAGEMENT_AT_RISK = 'AtRisk';
    public const ENGAGEMENT_INACTIVE = 'Inactive';

    /**
     * Process engagement emails: "at risk" warnings and inactive user re-engagement.
     *
     * @return array{at_risk_sent: int, inactive_sent: int}
     */
    public function processEngageEmails(bool $dryRun = false): array
    {
        $atRiskSent = 0;
        $inactiveSent = 0;

        // "At risk" — users who will become inactive in 7 days (send even if they've opted out of regular emails)
        $atRiskDate = now()->subSeconds(self::USER_INACTIVE_SECONDS)->addDays(self::AT_RISK_WARNING_DAYS)->toDateString();
        $atRiskUserIds = DB::table('users')
            ->whereRaw('DATE(lastaccess) = ?', [$atRiskDate])
            ->whereNull('deleted')
            ->pluck('id')
            ->toArray();

        $atRiskSent = $this->sendToUsers(self::ENGAGEMENT_AT_RISK, $atRiskUserIds, true, $dryRun);

        // "Inactive" — users already classified as Inactive in users.engagement
        $inactiveUserIds = DB::table('users')
            ->where('engagement', self::ENGAGEMENT_INACTIVE)
            ->whereNull('deleted')
            ->pluck('id')
            ->toArray();

        $inactiveSent = $this->sendToUsers(self::ENGAGEMENT_INACTIVE, $inactiveUserIds, false, $dryRun);

        return ['at_risk_sent' => $atRiskSent, 'inactive_sent' => $inactiveSent];
    }

    private function sendToUsers(string $engagement, array $userIds, bool $force, bool $dryRun): int
    {
        $count = 0;

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (!$user || !$user->email_preferred) {
                continue;
            }

            if (!$force && !$user->relevantallowed) {
                continue;
            }

            // Check the user has a Freegle group membership
            $hasMembership = DB::table('memberships')
                ->join('groups', 'groups.id', '=', 'memberships.groupid')
                ->where('memberships.userid', $userId)
                ->where('groups.type', 'Freegle')
                ->exists();

            if (!$hasMembership) {
                continue;
            }

            // Respect group-level engagement setting
            $engagementEnabled = DB::table('memberships')
                ->join('groups', 'groups.id', '=', 'memberships.groupid')
                ->where('memberships.userid', $userId)
                ->where('groups.type', 'Freegle')
                ->selectRaw('MAX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(memberships.settings, "$.engagement")), 1)) AS enabled')
                ->value('enabled');

            if ($engagementEnabled === '0' || $engagementEnabled === 0) {
                continue;
            }

            // Don't send within RESEND_INTERVAL_DAYS of last attempt
            $lastSent = DB::table('engage')
                ->where('userid', $userId)
                ->max('timestamp');

            if ($lastSent && now()->diffInDays($lastSent, false) > -self::RESEND_INTERVAL_DAYS) {
                continue;
            }

            $mail = $this->chooseMail($engagement);
            if (!$mail) {
                continue;
            }

            if (!$dryRun) {
                $engageId = $this->recordAttempt($userId, $mail->id, $engagement);
                $this->incrementShown($mail->id);

                $unsubscribeUrl = config('freegle.sites.user') . '/unsubscribe';

                Mail::send(new EngageMail(
                    recipientName: $user->display_name,
                    recipientEmail: $user->email_preferred,
                    emailSubject: $mail->subject,
                    template: $mail->template,
                    engageId: $engageId,
                    unsubscribeUrl: $unsubscribeUrl,
                ));
            }

            $count++;
        }

        return $count;
    }

    private function chooseMail(string $engagement): ?object
    {
        $variants = DB::table('engage_mails')
            ->where('engagement', $engagement)
            ->orderByDesc('rate')
            ->orderByRaw('RAND()')
            ->get();

        if ($variants->isEmpty()) {
            return null;
        }

        $r = mt_rand(0, 99) / 100.0;

        if ($r < 0.1 && $variants->count() > 1) {
            return $variants->get(random_int(1, $variants->count() - 1));
        }

        return $variants->first();
    }

    private function recordAttempt(int $userId, int $mailId, string $engagement): int
    {
        // engage.engagement enum doesn't include 'AtRisk' — store NULL for at-risk attempts
        $storedEngagement = in_array($engagement, ['UT', 'New', 'Occasional', 'Frequent', 'Obsessed', 'Inactive', 'Dormant'])
            ? $engagement
            : null;

        return (int) DB::table('engage')->insertGetId([
            'userid' => $userId,
            'mailid' => $mailId,
            'engagement' => $storedEngagement,
            'timestamp' => now(),
        ]);
    }

    private function incrementShown(int $mailId): void
    {
        DB::table('engage_mails')
            ->where('id', $mailId)
            ->increment('shown');

        DB::statement(
            'UPDATE engage_mails SET rate = COALESCE(100 * action / shown, 0) WHERE id = ?',
            [$mailId]
        );
    }
}
