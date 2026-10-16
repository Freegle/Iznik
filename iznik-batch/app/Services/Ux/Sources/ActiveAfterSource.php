<?php

namespace App\Services\Ux\Sources;

use App\Services\Ux\ExposureSource;
use Carbon\Carbon;

/**
 * A notification, measured by the member being active: a member converts when users_active has a row for
 * them within the experiment's window of being sent a push, a bell notification or an email. The app
 * records no tap, so this is the nearest measure there is.
 *
 * users_active has one row per member per hour, written when the site or app loads the member's list of
 * notifications (GET /notification). It does that when the member has something unread and the page polls,
 * or when they open the bell. A member who visits with nothing unread leaves no row, so this counts fewer
 * visits than there are, in both arms alike. The hour the notification went out counts. The guardrail for a
 * push is turning the daily posts push off: a counted member was opted in when sent, so no timestamp is needed.
 * docs/developers/reference/ux-experiments.md, "Experiments in mail, push and the server".
 *
 * Nothing records a push before the first run, so volume() has nothing to go on and the power check
 * is skipped. The experiment's own change carries its sizing.
 */
class ActiveAfterSource extends ExposureSource
{
    /** Turning a push off is rare, so it has the same small margin as unsubscribing. */
    public const OPT_OUT_MARGIN_POINTS = 0.3;

    public function types(): array
    {
        return ['push', 'bell', 'email'];
    }

    public function words(object $exp): array
    {
        $window = self::windowText($this->windowOf($exp));

        // Nothing else on the tab says what active means, so the note does.
        return [
            'who' => 'members',
            'started' => 'were counted',
            'finished' => 'were active within '.$window,
            'tab_started' => 'Counted',
            'tab_finished' => 'Active within '.$window,
            'note' => "Active means the site or app loaded the member's notifications, which it does when they have something unread or open the bell. "
                .'Each member is counted once, for the first notification they were sent, and only when that was at least '.$window.' ago. The hour it was sent counts.',
        ];
    }

    public function guardrails(object $exp): array
    {
        if (! $this->isPush($exp)) {
            return [];
        }

        return [
            'push_opt_out' => ['label' => 'the share of members who turned the push off', 'margin_points' => self::OPT_OUT_MARGIN_POINTS],
        ];
    }

    protected function joins(object $exp): string
    {
        return $this->isPush($exp) ? 'LEFT JOIN users u ON u.id = c.userid' : '';
    }

    protected function converted(object $exp): string
    {
        return "EXISTS (SELECT 1 FROM users_active ua WHERE ua.userid = c.userid AND ua.timestamp >= DATE_FORMAT(c.exposed_at, '%Y-%m-%d %H:00:00') AND ua.timestamp < c.exposed_at + INTERVAL :window HOUR)";
    }

    protected function bad(object $exp): array
    {
        if (! $this->isPush($exp)) {
            return [];
        }

        // CASE, because the settings are JSON text and a row that is not valid JSON must count as "not off".
        return [
            'push_opt_out' => "CASE WHEN JSON_VALID(u.settings) THEN JSON_UNQUOTE(JSON_EXTRACT(u.settings, '$.notifications.dailypostspush')) = 'false' ELSE 0 END",
        ];
    }

    public function volume(object $exp, Carbon $now): ?array
    {
        return null;
    }

    private function isPush(object $exp): bool
    {
        return ($this->designOf($exp)['channel'] ?? '') === 'push';
    }
}
