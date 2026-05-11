<?php

namespace App\Services;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class LoveJunkInvoiceService
{
    /**
     * Calculate the TN/FD split for LoveJunk posts last month and send an invoice request.
     *
     * @param  string|null  $period  Optional period string (e.g. "2026-03") — defaults to last month
     * @param  bool  $dryRun  If true, log but do not send the email
     * @return array{tn: int, fd: int, tn_percent: int, fd_percent: int, tn_amount: float}
     */
    public function sendInvoice(?string $period = null, bool $dryRun = false): array
    {
        if ($period) {
            $start = $period . '-01';
            $end = date('Y-m-d', strtotime($start . ' +1 month'));
        } else {
            $start = date('Y-m-d', strtotime('first day of last month'));
            $end = date('Y-m-d', strtotime('first day of this month'));
        }

        $rows = DB::select(
            "SELECT
                COUNT(DISTINCT TRIM(REGEXP_REPLACE(messages.subject, '\\\\[.*?\\\\](.*)', '$1'))) AS count,
                CASE WHEN messages.sourceheader LIKE 'TN-%' THEN 1 ELSE 0 END AS tn
             FROM messages
             INNER JOIN lovejunk ON lovejunk.msgid = messages.id
             WHERE lovejunk.timestamp >= ? AND lovejunk.timestamp < ?
             GROUP BY tn
             ORDER BY tn ASC",
            [$start, $end]
        );

        $fdCount = 0;
        $tnCount = 0;

        foreach ($rows as $row) {
            if ($row->tn) {
                $tnCount = (int) $row->count;
            } else {
                $fdCount = (int) $row->count;
            }
        }

        $total = $tnCount + $fdCount;

        if ($total === 0) {
            return ['tn' => 0, 'fd' => 0, 'tn_percent' => 0, 'fd_percent' => 0, 'tn_amount' => 0.0];
        }

        $tnPercent = (int) round(($tnCount / $total) * 100);
        $fdPercent = 100 - $tnPercent;
        $tnAmount = round(($tnCount / $total) * 500, 2);

        $tnAddr = config('freegle.mail.tn_invoice_addr');
        $treasurerAddr = config('freegle.mail.treasurer_addr');
        $geeksAddr = config('freegle.mail.geeks_addr');

        $body = "Please raise an invoice on Freegle Ltd for your share of the LoveJunk advertising income, "
            . "which is £{$tnAmount} for the period from {$start} (inclusive) to {$end} (exclusive). "
            . "During this period TN provided {$tnPercent}% of the posts sent to LoveJunk.\n\n"
            . "The invoice should be emailed as a PDF attachment to {$treasurerAddr}. Thanks!";

        if (!$dryRun && $tnAddr) {
            Mail::raw($body, function (Message $message) use ($tnAddr, $geeksAddr, $start, $end) {
                $message->from($geeksAddr, 'Freegle')
                    ->to($tnAddr)
                    ->subject('Please raise an invoice');
            });
        }

        return [
            'tn' => $tnCount,
            'fd' => $fdCount,
            'tn_percent' => $tnPercent,
            'fd_percent' => $fdPercent,
            'tn_amount' => $tnAmount,
        ];
    }
}
