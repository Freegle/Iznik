<?php

namespace App\Services\CommunityNews;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Deterministic backstop for the research model's event_date omissions.
 *
 * The past-events filter (email + ChitChat) can only act on
 * community_news_items.event_date, but in practice the model omits it for ~96%
 * of items — including ones whose own blurb says "On Saturday 8 August ..."
 * (the 2026-08-14 send mailed a food festival six days gone). The blurbs are
 * REQUIRED by the prompt to carry absolute dates, so the reliable source of
 * truth is the text itself: parse every explicit "8 August" / "8th August
 * 2026" mention and use the LATEST one — a range like "Thursday 13 to Sunday
 * 16 August" only yields "16 August" (the "13 to" half carries no month), which
 * is exactly the end date we want an item judged by.
 */
final class MentionedDates
{
    private const MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
        'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    /**
     * The latest explicit day-month(-year) date mentioned in the text, or null
     * when it mentions none.
     */
    public static function latest(string $text, CarbonInterface $now): ?Carbon
    {
        $months = implode('|', array_keys(self::MONTHS));
        if (!preg_match_all(
            "/\\b(\\d{1,2})(?:st|nd|rd|th)?\\s+({$months})(?:\\s+(\\d{4}))?\\b/i",
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            return null;
        }

        $latest = null;

        foreach ($matches as $m) {
            $day = (int) $m[1];
            $month = self::MONTHS[strtolower($m[2])];
            $explicitYear = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;

            $year = $explicitYear ?? (int) $now->year;
            if (!checkdate($month, $day, $year)) {
                continue;
            }
            $candidate = Carbon::create($year, $month, $day)->startOfDay();

            // A yearless mention months behind now almost certainly means next
            // year ("8 January" written in December): research writes about the
            // near future, never the distant past.
            if ($explicitYear === null && $candidate->lt($now->copy()->subMonths(6)->startOfDay())) {
                if (!checkdate($month, $day, $year + 1)) {
                    continue;
                }
                $candidate = Carbon::create($year + 1, $month, $day)->startOfDay();
            }

            if ($latest === null || $candidate->gt($latest)) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    /**
     * True when the item is visibly over: no event_date (the DB filter handles
     * those) but its text's latest mentioned date is before today.
     */
    public static function visiblyOver(object $item, CarbonInterface $now): bool
    {
        if ($item->event_date !== null) {
            return false;
        }

        $mentioned = self::latest(trim($item->title . ' ' . $item->snippet), $now);

        return $mentioned !== null && $mentioned->lt($now->copy()->startOfDay());
    }
}
