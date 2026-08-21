<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Stands in for the spatial server's ring index in tests.
 *
 * The batch stopped testing ring geometry in SQL on 2026-08-21: every surface —
 * browse, search, the badge, the message page, the web reply gate, the mail, and
 * the emailed-reply gate — now asks the one index instead, because two
 * derivations of "does a ring admit this member" drifted apart in production and
 * people were invited to posts they could not see.
 *
 * So a test that seeds a ring has to serve one. This fake answers FROM THE SEEDED
 * ROWS, which keeps each test meaning what it meant: the ring the test planted is
 * still what decides, and the assertions still read as statements about bands,
 * wedges and lane flags rather than about HTTP. The test rings are rectangles, so
 * a bounding-box test against their WKT is exact for them.
 */
trait FakesRingIndex
{
    protected function fakeRingIndex(): void
    {
        Http::fake($this->ringIndexStubs());
    }

    /**
     * The stubs alone, for a test that also fakes something else.
     *
     * Http::fake REPLACES previous stubs rather than merging, so a test that fakes
     * the deprivation lookup would otherwise silently un-fake the ring index and
     * assert against "no rings" while believing it was asserting about bands.
     *
     * @return array<string, callable>
     */
    protected function ringIndexStubs(): array
    {
        return [
            '*/v1/reachoverflow/admits' => function ($request) {
                $body = $request->data();
                $bounds = $this->seededBounds((int) ($body['msgid'] ?? 0));

                $admitted = [];
                foreach (($body['points'] ?? []) as $i => $point) {
                    foreach ((array) ($point['lanes'] ?? []) as $lane) {
                        $wkt = $this->ringWkt($bounds, $lane);
                        if ($wkt !== null && $this->covers($wkt, (float) $point['lng'], (float) $point['lat'])) {
                            $admitted[] = $i;
                            break;
                        }
                    }
                }

                return Http::response(['admitted' => $admitted]);
            },

            '*/v1/reachoverflow/containing*' => function ($request) {
                $query = [];
                parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
                $lng = (float) ($query['lng'] ?? 0);
                $lat = (float) ($query['lat'] ?? 0);
                $lanes = array_filter(explode(',', (string) ($query['lanes'] ?? '')));

                $in = [];
                $rows = DB::table('rippling_reach')
                    ->whereNotNull('overflow_bounds')
                    ->get(['msgid', 'overflow_bounds']);
                foreach ($rows as $row) {
                    $bounds = json_decode((string) $row->overflow_bounds, true);
                    foreach ($lanes as $lane) {
                        $wkt = $this->ringWkt(is_array($bounds) ? $bounds : null, $lane);
                        if ($wkt !== null && $this->covers($wkt, $lng, $lat)) {
                            $in[] = (int) $row->msgid;
                            break;
                        }
                    }
                }

                return Http::response(['in' => $in, 'partial' => []]);
            },
        ];
    }

    /** The ring index unreachable: nobody is admitted, on any surface. */
    protected function fakeRingIndexDown(): void
    {
        Http::fake([
            '*/v1/reachoverflow/*' => Http::response(['error' => 'dataset not ready'], 503),
        ]);
    }

    private function seededBounds(int $msgid): ?array
    {
        $raw = DB::table('rippling_reach')->where('msgid', $msgid)->value('overflow_bounds');
        $bounds = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($bounds) ? $bounds : null;
    }

    /** Resolve a lane path ($.rural.sparse, $.cluster.w1, $.fairness."1") in the seeded JSON. */
    private function ringWkt(?array $bounds, string $lane): ?string
    {
        if ($bounds === null || ! str_starts_with($lane, '$.')) {
            return null;
        }

        $parts = explode('.', substr($lane, 2), 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$family, $key] = $parts;
        $key = trim($key, '"');

        $wkt = $bounds[$family][$key] ?? null;

        return is_string($wkt) && $wkt !== '' ? $wkt : null;
    }

    /** Bounding-box containment, exact for the rectangles the fixtures plant. */
    private function covers(string $wkt, float $lng, float $lat): bool
    {
        if (! preg_match_all('/(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)/', $wkt, $m, PREG_SET_ORDER)) {
            return false;
        }

        $xs = array_map(static fn ($p) => (float) $p[1], $m);
        $ys = array_map(static fn ($p) => (float) $p[2], $m);

        return $lng >= min($xs) && $lng <= max($xs) && $lat >= min($ys) && $lat <= max($ys);
    }
}
