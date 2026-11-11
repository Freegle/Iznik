<?php

namespace App\Services\Ux\Sources;

use App\Services\Ux\ExposureSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mail, measured by a click: a member converts when they click a link in the email within the
 * experiment's window of it being sent, and the unsubscribe link does not count. The guardrail is
 * unsubscribing in the same window.
 *
 * Opens are never used: they are folded in nightly and image proxies open mail for people who never
 * looked at it. The exposure's subject is the email_tracking row, so every arm of an experiment
 * counted this way must send the email, and the row is kept for 30 days, enough for a run and its
 * holdout. docs/developers/reference/ux-experiments.md, "Experiments in mail, push and the server".
 *
 * The unsubscribe link also sets clicked_at, which is why a click only counts while unsubscribed_at
 * is empty. The click handler does not filter mail scanners, which click both arms alike.
 */
class EmailClickSource extends ExposureSource
{
    /** A 0.2 percent unsubscribe rate is far too small for the 2 point default, so this is its own. */
    public const UNSUBSCRIBE_MARGIN_POINTS = 0.3;

    public function types(): array
    {
        return ['email'];
    }

    public function words(object $exp): array
    {
        $window = self::windowText($this->windowOf($exp));

        // The number is the members counted: those first sent the email at least a window ago, who are fewer than
        // the members sent so far. The tab writes "Exposed" for the members sent so far, so "Counted" is not read as the number sent.
        return [
            'who' => 'members',
            'started' => 'were counted',
            'finished' => 'clicked a link within '.$window,
            'tab_started' => 'Counted',
            'tab_finished' => 'Clicked within '.$window,
            'note' => 'Each member is counted once, for the first email they were sent, and only when that was at least '.$window.' ago.',
        ];
    }

    public function guardrails(object $exp): array
    {
        return [
            'unsubscribe' => ['label' => 'the share of members who unsubscribed', 'margin_points' => self::UNSUBSCRIBE_MARGIN_POINTS],
        ];
    }

    protected function joins(object $exp): string
    {
        return 'LEFT JOIN email_tracking t ON t.id = c.subject_id';
    }

    protected function converted(object $exp): string
    {
        return 't.clicked_at < c.exposed_at + INTERVAL :window HOUR AND t.unsubscribed_at IS NULL';
    }

    protected function bad(object $exp): array
    {
        return [
            'unsubscribe' => 't.unsubscribed_at IS NOT NULL AND t.unsubscribed_at < c.exposed_at + INTERVAL :window HOUR',
        ];
    }

    /**
     * The members this email goes to over a run, halved for each arm, and the click rate they have:
     * from the sends of this email type in the last intake_hours that have had the whole window to
     * be clicked. Null when there are none, which skips the power check.
     */
    public function volume(object $exp, Carbon $now): ?array
    {
        $design = $this->designOf($exp);
        $type = $design['type'] ?? null;
        if (! $type) {
            return null;
        }

        $intake = (int) ($design['intake_hours'] ?? config('freegle.ux.channels.email.intake_hours', 24));
        $to = $now->copy()->utc()->subHours($this->windowOf($exp));
        $from = $to->copy()->subHours(max(1, $intake));

        // A size estimate, so the read connection will do however far behind it is.
        $sends = fn () => DB::table('email_tracking')
            ->where('email_type', $type)->whereNotNull('userid')
            ->where('sent_at', '>=', $from->format('Y-m-d H:i:s'))->where('sent_at', '<', $to->format('Y-m-d H:i:s'));

        $total = $sends()->count();
        $members = $sends()->distinct()->count('userid');
        if ($total === 0 || $members < 2) {
            return null;
        }
        $clicked = $sends()->whereNotNull('clicked_at')->whereNull('unsubscribed_at')->count();

        return ['per_arm' => intdiv($members, 2), 'rate' => $clicked / $total];
    }
}
