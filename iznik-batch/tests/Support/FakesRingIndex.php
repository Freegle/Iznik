<?php

namespace Tests\Support;

use App\Services\Ripple\CellSetService;
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
 * ROWS' overflow_cells, which keeps each test meaning what it meant: the ring the
 * test planted is still what decides, and the assertions still read as statements
 * about bands, wedges and lane flags rather than about HTTP. Containment is a
 * probe of the seeded lane's own cell grid, so it is exact by construction.
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
                        $cells = $this->ringCells($bounds, $lane);
                        if ($cells !== null && $this->covers($cells, (float) $point['lng'], (float) $point['lat'])) {
                            $admitted[] = $i;
                            break;
                        }
                    }
                }

                return Http::response(['admitted' => $admitted]);
            },

            // The reach index proper (the daily digest's containment
            // universe): which seeded rows' polygon_cells cover the point.
            // Same authority as production, answered from the same bytes.
            '*/v1/reach/containing*' => function ($request) {
                $query = [];
                parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
                $lng = (float) ($query['lng'] ?? 0);
                $lat = (float) ($query['lat'] ?? 0);

                $svc = new CellSetService();
                $in = [];
                $rows = DB::table('rippling_reach')
                    ->where('status', '!=', 'held')
                    ->whereNotNull('polygon_cells')
                    ->get(['msgid', 'polygon_cells']);
                foreach ($rows as $row) {
                    if ($svc->containsEncoded((string) $row->polygon_cells, $lng, $lat) === true) {
                        $in[] = (int) $row->msgid;
                    }
                }

                return Http::response(['in' => $in, 'partial' => []]);
            },

            '*/v1/reachoverflow/containing*' => function ($request) {
                $query = [];
                parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
                $lng = (float) ($query['lng'] ?? 0);
                $lat = (float) ($query['lat'] ?? 0);
                $lanes = array_filter(explode(',', (string) ($query['lanes'] ?? '')));

                $in = [];
                $rows = DB::table('rippling_reach')
                    ->whereNotNull('overflow_cells')
                    ->get(['msgid', 'overflow_cells']);
                foreach ($rows as $row) {
                    $bounds = json_decode((string) $row->overflow_cells, true);
                    foreach ($lanes as $lane) {
                        $cells = $this->ringCells(is_array($bounds) ? $bounds : null, $lane);
                        if ($cells !== null && $this->covers($cells, $lng, $lat)) {
                            $in[] = (int) $row->msgid;
                            break;
                        }
                    }
                }

                // filtered:true, as the real server reports when it narrowed by
                // lane - the clients refuse an unfiltered answer, because those
                // ids would be packed ones and not msgids at all.
                return Http::response(['in' => $in, 'partial' => [], 'filtered' => true]);
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
        $raw = DB::table('rippling_reach')->where('msgid', $msgid)->value('overflow_cells');
        $bounds = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($bounds) ? $bounds : null;
    }

    /**
     * Resolve a lane path ($.rural.sparse, $.cluster.w1, $.fairness."1") in the
     * seeded JSON to the lane's raw cell bytes.
     */
    private function ringCells(?array $bounds, string $lane): ?string
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

        $b64 = $bounds[$family][$key] ?? null;
        if (! is_string($b64) || $b64 === '') {
            return null;
        }
        $raw = base64_decode($b64, true);

        return $raw === false ? null : $raw;
    }

    /** Containment by probing the seeded lane's own cell grid - exact. */
    private function covers(string $cells, float $lng, float $lat): bool
    {
        return (new CellSetService())->containsEncoded($cells, $lng, $lat) === true;
    }
}
