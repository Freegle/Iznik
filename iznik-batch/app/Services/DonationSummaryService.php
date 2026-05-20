<?php

namespace App\Services;

use App\Mail\Donation\DonationSummaryMail;
use App\Models\Group;
use App\Support\SafeMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DonationSummaryService
{
    private const RECURRING_TYPES = ['recurring_payment', 'subscr_payment'];

    /**
     * Send daily donation summary email.
     *
     * Queries today's donations, annotates each with recurring/birthday flags,
     * and sends an HTML summary to the fundraising address.
     *
     * @return array{donations: int, total: float, sent: bool}
     */
    public function sendDailySummary(bool $dryRun = false): array
    {
        $fundraisingAddr = config('freegle.mail.fundraising_addr');

        $donations = DB::table('users_donations')
            ->whereRaw('DATE(users_donations.timestamp) = DATE(NOW())')
            ->orderByDesc('timestamp')
            ->get();

        if ($donations->isEmpty()) {
            return ['donations' => 0, 'total' => 0.0, 'sent' => false];
        }

        $total    = 0.0;
        $rowsHtml = '';

        foreach ($donations as $donation) {
            $total += (float) $donation->GrossAmount;

            $recurring = in_array($donation->TransactionType, self::RECURRING_TYPES, true);
            $birthday  = false;

            if ($donation->userid) {
                $skipBirthdayCheck = false;

                if ($recurring) {
                    $lastMonth = DB::table('users_donations')
                        ->where('userid', $donation->userid)
                        ->where('GrossAmount', $donation->GrossAmount)
                        ->whereIn('TransactionType', self::RECURRING_TYPES)
                        ->whereRaw('DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)')
                        ->whereRaw('DATE(timestamp) < CURDATE()')
                        ->count();

                    $skipBirthdayCheck = $lastMonth > 0;
                }

                if (!$skipBirthdayCheck) {
                    $birthday = $this->donorHasBirthdayGroup((int) $donation->userid);
                }
            }

            $flags = [];
            if ($recurring) {
                $flags[] = 'Recurring';
            }
            if ($birthday) {
                $flags[] = 'Birthday?';
            }

            $statusCell = implode(', ', $flags);
            $amount     = number_format((float) $donation->GrossAmount, 2);
            $payer      = htmlspecialchars((string) ($donation->Payer ?? ''), ENT_QUOTES);

            // users_donations.timestamp is stored in UTC (the batch container's
            // MySQL session and PHP both run UTC). V1's mail implicitly showed
            // UK local because it ran on a Europe/London-locale host; preserve
            // that here so a 10:03 UTC PayPal IPN shows as 11:03 BST in the
            // fundraising email, not 10:03.
            //
            // Drop the date — every row in a daily summary is today already —
            // so the time column doesn't push the payer column off-screen on
            // mobile. Keep the TZ name so DST transitions are obvious at a
            // glance.
            $localTs = Carbon::parse($donation->timestamp, 'UTC')
                ->setTimezone('Europe/London')
                ->format('H:i:s T');

            // Cell styling is inline because MJML's <mj-table> doesn't
            // propagate per-cell rules. The donation-row class is what the
            // template's media query targets to tighten padding + font on
            // narrow screens.
            $td       = 'style="padding:4px 6px;border-bottom:1px solid #eee;text-align:left;"';
            $tdTime   = 'style="padding:4px 6px;border-bottom:1px solid #eee;text-align:left;white-space:nowrap;"';
            $tdAmount = 'style="padding:4px 6px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap;"';
            $tdPayer  = 'style="padding:4px 6px;border-bottom:1px solid #eee;text-align:left;word-break:break-word;"';
            $rowsHtml .= "<tr class=\"donation-row\">"
                . "<td {$tdTime}>{$localTs}</td>"
                . "<td {$tdAmount}><b>&pound;{$amount}</b></td>"
                . "<td {$tdPayer}>{$payer}</td>"
                . "<td {$td}>{$statusCell}</td>"
                . "</tr>\n";
        }

        if (!$dryRun) {
            // Pass only the row HTML — the template uses <mj-table> which
            // emits its own <table><tbody>. Previously we passed a full
            // <table><tbody>...</table> through <mj-raw>, which MJML injected
            // straight into the column's layout <tbody>, producing
            // <tbody><table>...</table></tbody>. That's invalid HTML and
            // most email clients (Gmail, Outlook) sanitised it away, which is
            // why the rendered email arrived without the donations list.
            //
            // SafeMail::sendMailable catches transient mail-host timeouts so
            // the daily cron logs at warning level instead of escalating to
            // Sentry (production hit this on 2026-05-15 08:01). The mailable
            // already sets recipientEmail via its envelope, so sendMailable
            // (not send) avoids duplicating the To: header.
            SafeMail::sendMailable(
                new DonationSummaryMail(
                    recipientEmail: $fundraisingAddr,
                    htmlContent: $rowsHtml,
                    total: $total,
                ),
                $fundraisingAddr,
            );
        }

        return [
            'donations' => $donations->count(),
            'total'     => $total,
            'sent'      => !$dryRun,
        ];
    }

    private function donorHasBirthdayGroup(int $userId): bool
    {
        $today     = date('m-d');
        $yesterday = date('m-d', strtotime('-1 day'));
        $twoDaysAgo = date('m-d', strtotime('-2 days'));

        $count = DB::table('groups')
            ->join('memberships', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $userId)
            ->where('groups.type', Group::TYPE_FREEGLE)
            ->where('groups.publish', 1)
            ->where('groups.onmap', 1)
            ->whereRaw("DATE_FORMAT(groups.founded, '%m-%d') IN (?, ?, ?)", [$today, $yesterday, $twoDaysAgo])
            ->whereRaw("YEAR(NOW()) - YEAR(groups.founded) > 0")
            ->count();

        return $count > 0;
    }
}
