<?php

namespace App\Services;

use App\Models\Group;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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
        $noReplyAddr     = config('freegle.mail.noreply_addr');

        $donations = DB::table('users_donations')
            ->whereRaw('DATE(users_donations.timestamp) = DATE(NOW())')
            ->orderByDesc('timestamp')
            ->get();

        if ($donations->isEmpty()) {
            return ['donations' => 0, 'total' => 0.0, 'sent' => false];
        }

        $total = 0.0;
        $rows  = '';

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

            $rows .= "<tr>"
                . "<td>{$donation->timestamp}</td>"
                . "<td><b>&pound;{$amount}</b></td>"
                . "<td>{$payer}</td>"
                . "<td>{$statusCell}</td>"
                . "</tr>\n";
        }

        $totalFormatted = number_format($total, 2);
        $html = "<table><tbody>{$rows}</tbody></table>";
        $subject = "Donation total today £{$totalFormatted}";

        if (!$dryRun) {
            Mail::html($html, function ($message) use ($subject, $noReplyAddr, $fundraisingAddr) {
                $message->from($noReplyAddr, 'Freegle')
                    ->to($fundraisingAddr)
                    ->subject($subject);
            });
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
