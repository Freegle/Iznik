<?php

namespace App\Services\Mail;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-domain delivery health, from open tracking.
 *
 * We hand mail to a smarthost and it accepts everything, so nothing in the spool tells us
 * whether a receiving provider actually delivered it. When a big provider starts refusing or
 * silently binning our mail, the spool looks perfectly healthy and the digest lag figures look
 * perfectly healthy, because from our side the mail went out.
 *
 * The one signal we do have is that people stop opening. email_tracking records sent_at and
 * opened_at per recipient, so an abrupt collapse in a domain's open rate is a proxy for its
 * mail no longer arriving.
 *
 * This is a comparison against each domain's OWN recent history, not against a fixed rate or
 * against other domains. Open rates differ enormously and legitimately between providers -
 * icloud.com opens at 56% and gmail.com at 23% on the same mail - so an absolute threshold
 * would either miss real outages at the high end or cry wolf at the low end. It also means
 * domains that never report opens at all (user.trashnothing.com forwards our mail on, so the
 * pixel is never fetched from the real recipient) drop out on their own: their baseline is
 * already zero, so there is nothing to lose.
 *
 * The recent window stops short of now. An email sent ten minutes ago has not had a fair chance
 * to be opened, and including it would make every run look like the start of an outage.
 *
 * COST. One pass over email_tracking's sent_at range, which at the default windows is about 4.5
 * million rows on production, grouped down to a few thousand domains. That is fine once a day
 * and would not be fine any more often than that.
 */
class DeliveryHealthService
{
    /** Ignore the last few hours - mail that recent has not had a fair chance to be opened. */
    public const SETTLE_HOURS = 6;

    /** A domain needs this many sends in the recent window before its rate means anything. */
    public const MIN_RECENT_SENDS = 500;

    /** ...and this open rate in its own baseline, or there is no working delivery to have lost. */
    public const MIN_BASELINE_OPEN_PERCENT = 5.0;

    /** Flag when the recent rate falls to this fraction of the domain's own baseline. */
    public const COLLAPSE_RATIO = 0.4;

    /**
     * Domains whose open rate has collapsed against their own baseline, worst first.
     *
     * @param int $recentDays Length of the window being judged.
     * @param int $baselineDays Length of the comparison window immediately before it.
     * @return array<int, array{domain: string, recent_sent: int, recent_open_percent: float,
     *                          baseline_open_percent: float, ratio: float}>
     */
    public function collapsedDomains(int $recentDays = 1, int $baselineDays = 14, ?Carbon $now = null): array
    {
        $recentEnd = ($now ? $now->copy() : Carbon::now())->subHours(self::SETTLE_HOURS);
        $recentStart = $recentEnd->copy()->subDays($recentDays);
        $baselineStart = $recentStart->copy()->subDays($baselineDays);

        // One pass over the sent_at range, splitting the two windows with conditional sums.
        $rows = DB::table('email_tracking')
            ->selectRaw("SUBSTRING_INDEX(recipient_email, '@', -1) AS domain")
            ->selectRaw('SUM(sent_at >= ?) AS recent_sent', [$recentStart])
            ->selectRaw('SUM(sent_at >= ? AND opened_at IS NOT NULL) AS recent_opened', [$recentStart])
            ->selectRaw('SUM(sent_at < ?) AS baseline_sent', [$recentStart])
            ->selectRaw('SUM(sent_at < ? AND opened_at IS NOT NULL) AS baseline_opened', [$recentStart])
            ->where('sent_at', '>=', $baselineStart)
            ->where('sent_at', '<', $recentEnd)
            ->groupBy('domain')
            ->havingRaw('recent_sent >= ?', [self::MIN_RECENT_SENDS])
            ->get();

        $collapsed = [];

        foreach ($rows as $row) {
            $recentSent = (int) $row->recent_sent;
            $baselineSent = (int) $row->baseline_sent;

            if ($recentSent < self::MIN_RECENT_SENDS || $baselineSent < self::MIN_RECENT_SENDS) {
                continue;
            }

            $baselinePercent = 100 * (int) $row->baseline_opened / $baselineSent;

            // Nothing to lose: this domain never reported opens even when it was healthy.
            if ($baselinePercent < self::MIN_BASELINE_OPEN_PERCENT) {
                continue;
            }

            $recentPercent = 100 * (int) $row->recent_opened / $recentSent;
            $ratio = $recentPercent / $baselinePercent;

            if ($ratio > self::COLLAPSE_RATIO) {
                continue;
            }

            $collapsed[] = [
                'domain' => $row->domain,
                'recent_sent' => $recentSent,
                'recent_open_percent' => round($recentPercent, 1),
                'baseline_open_percent' => round($baselinePercent, 1),
                'ratio' => round($ratio, 2),
            ];
        }

        // Worst first, then by how much mail is affected, so the log leads with what matters.
        usort($collapsed, static function (array $a, array $b): int {
            return [$a['ratio'], -$a['recent_sent']] <=> [$b['ratio'], -$b['recent_sent']];
        });

        return $collapsed;
    }
}
