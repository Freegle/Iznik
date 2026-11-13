<?php

namespace App\Services\TrashNothing\Sync;

/**
 * Reads a TN feed a day at a time: /ratings and /user-changes answer 400 to a date_min and date_max
 * more than a day apart, as /posts/all does. The class using this provides fetchPage(), PAGE_SIZE,
 * $localTesting and $fetchFailed.
 */
trait ReadsTnFeedByDay
{
    /**
     * Each page from $from to $to, oldest day first, keyed by its page number within its day. It
     * stops at a page that cannot be read and sets $fetchFailed: the feed's order is TN's, so what
     * was read says nothing about what was not.
     *
     * @return \Generator<int, array>
     */
    private function pages(string $from, string $to): \Generator
    {
        $this->fetchFailed = false;

        // Fixtures take no notice of dates, so every day would read the same ones again.
        $windows = $this->localTesting ? [[$from, $to]] : $this->dayWindows($from, $to);

        foreach ($windows as [$dayFrom, $dayTo]) {
            $page = 1;

            do {
                $rows = $this->fetchPage($page, $dayFrom, $dayTo);
                if ($rows === null) {
                    $this->fetchFailed = true;

                    return;
                }

                yield $page => $rows;

                $page++;
            } while ($rows && count($rows) === self::PAGE_SIZE);
        }
    }

    /**
     * @return list<array{string, string}> date_min and date_max for each day. A window that is
     *                                     already short enough goes to TN just as it was given.
     */
    private function dayWindows(string $from, string $to): array
    {
        $utc     = new \DateTimeZone('UTC');
        $end     = new \DateTimeImmutable($to, $utc);
        $next    = (new \DateTimeImmutable($from, $utc))->setTimezone($utc)->modify('+1 day');
        $windows = [];

        while ($next < $end) {
            $boundary  = $next->format('Y-m-d\TH:i:s\Z');
            $windows[] = [$from, $boundary];
            $from      = $boundary;
            $next      = $next->modify('+1 day');
        }

        $windows[] = [$from, $to];

        return $windows;
    }
}
