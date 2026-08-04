<?php

namespace App\Console\Commands\Browse;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile settings.browseMaxDistance with settings.browseMaxMinutes.
 *
 * The "How far away" slider stores a travel-time budget in minutes (the source
 * of truth) and a derived crow-flies mile radius that the fast Haversine feed
 * filter and the digest DistancePreferenceFilter actually read. The slider used
 * to keep the OLD radius when the minutes->miles routing lookup failed, so the
 * pair could diverge: seen live as a slider showing 25 minutes while the feed
 * stayed capped at a stale 1 mile, which the member experienced as "I only see
 * old posts". The slider now fails open, but every already-diverged pair stays
 * wrong until recomputed - which is this command's job.
 *
 * For each user with browseMaxMinutes set:
 *   - minutes at the no-limit stop (>= 30): distance must be the shared
 *     unlimited sentinel.
 *   - minutes below it: distance is recomputed from the same routing-backed
 *     endpoint the slider uses (town/near reach_radius_miles) at the user's
 *     location, and corrected when it differs by more than --epsilon-miles.
 *   - no known location, or the routing call fails: SKIPPED, never clobbered -
 *     a batch job must not loosen or tighten someone's feed on a lookup blip.
 *
 * Users with a distance but NO minutes are pre-2026-07-10 miles-slider writes.
 * Since the slider went time-based it has shown them "no limit" while their old
 * cap silently kept filtering - so their stored state is overridden to the
 * unlimited sentinel to match what the UI has been telling them (explicitly
 * decided 2026-08-04). An old app bundle that still runs the miles slider may
 * re-write a cap; that re-write is a current, deliberate act and stands.
 *
 * Chunked by id and re-runnable: it only ever moves a pair TOWARDS the
 * invariant, so stopping and re-running is safe.
 */
class BackfillBrowseMaxDistanceCommand extends Command
{
    /**
     * Shared "no limit" sentinel - must stay byte-identical with
     * iznik-nuxt3/constants.js BROWSE_DISTANCE_UNLIMITED,
     * iznik-server-go/isochrone/message.go BrowseDistanceUnlimited and
     * DistancePreferenceFilter::DISTANCE_UNLIMITED.
     */
    private const DISTANCE_UNLIMITED = 9007199254740991;

    private const MINUTES_NO_LIMIT = 30;

    protected $signature = 'browse:backfill-max-distance
                            {--chunk=200 : Users per DB chunk}
                            {--limit=0 : Stop after this many corrections (0 = no limit)}
                            {--epsilon-miles=0.5 : Leave pairs alone when the recomputed radius is within this of the stored one}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Reconcile stale settings.browseMaxDistance values with the browseMaxMinutes source of truth';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $epsilon = max(0.0, (float) $this->option('epsilon-miles'));
        $apiBase = rtrim(config('freegle.town_near_url'), '/');

        $stats = [
            'scanned' => 0,
            'corrected' => 0,
            'already_consistent' => 0,
            'no_location' => 0,
            'lookup_failed' => 0,
        ];

        User::query()
            ->whereNull('deleted')
            ->whereRaw("(JSON_EXTRACT(settings, '$.browseMaxMinutes') IS NOT NULL OR JSON_EXTRACT(settings, '$.browseMaxDistance') IS NOT NULL)")
            ->orderBy('id')
            ->chunkById($chunk, function ($users) use ($dryRun, $limit, $epsilon, $apiBase, &$stats) {
                foreach ($users as $user) {
                    if ($limit > 0 && $stats['corrected'] >= $limit) {
                        return false;
                    }

                    $stats['scanned']++;
                    $settings = $user->settings ?? [];
                    $minutes = isset($settings['browseMaxMinutes'])
                        ? (int) $settings['browseMaxMinutes']
                        : null;
                    $current = $settings['browseMaxDistance'] ?? null;

                    $desired = $this->desiredDistance($user, $minutes, $apiBase, $stats);
                    if ($desired === null) {
                        continue;
                    }

                    if ($this->consistent($current, $desired, $epsilon)) {
                        $stats['already_consistent']++;

                        continue;
                    }

                    $this->line(sprintf(
                        '%suser %d: minutes=%d distance %s -> %s',
                        $dryRun ? '[dry-run] ' : '',
                        $user->id,
                        $minutes,
                        $current === null ? 'NULL' : (string) $current,
                        (string) $desired,
                    ));

                    if (! $dryRun) {
                        $settings['browseMaxDistance'] = $desired;
                        $user->settings = $settings;
                        $user->save();
                    }
                    $stats['corrected']++;
                }
            });

        $this->info(sprintf(
            '%d scanned: %d %s, %d already consistent, %d skipped (no location), %d skipped (lookup failed).',
            $stats['scanned'],
            $stats['corrected'],
            $dryRun ? 'would be corrected' : 'corrected',
            $stats['already_consistent'],
            $stats['no_location'],
            $stats['lookup_failed'],
        ));

        if (! $dryRun && $stats['corrected'] > 0) {
            Log::info('browse:backfill-max-distance', $stats);
        }

        return Command::SUCCESS;
    }

    /**
     * The distance the invariant wants for this user, or null when it cannot be
     * determined honestly (no location / lookup failure) and the pair must be
     * left alone.
     */
    private function desiredDistance(User $user, ?int $minutes, string $apiBase, array &$stats): int|float|null
    {
        // No minutes at all: a pre-2026-07-10 miles-slider write. The time-based
        // slider shows these members "no limit", so storage is overridden to
        // match the UI (see class docblock). No routing call needed.
        if ($minutes === null) {
            return self::DISTANCE_UNLIMITED;
        }

        if ($minutes >= self::MINUTES_NO_LIMIT) {
            return self::DISTANCE_UNLIMITED;
        }

        $loc = DB::table('locations')
            ->where('id', $user->lastlocation)
            ->select('lat', 'lng')
            ->first();
        if (! $loc || $loc->lat === null || $loc->lng === null) {
            $stats['no_location']++;

            return null;
        }

        try {
            $response = Http::timeout(10)->get($apiBase, [
                'lat' => $loc->lat,
                'lng' => $loc->lng,
                'minutes' => $minutes,
            ]);
            $radius = $response->successful() ? $response->json('reach_radius_miles') : null;
        } catch (\Throwable $e) {
            $radius = null;
        }

        if (! is_numeric($radius) || $radius <= 0) {
            $stats['lookup_failed']++;

            return null;
        }

        return $radius;
    }

    private function consistent(mixed $current, int|float $desired, float $epsilon): bool
    {
        if (! is_numeric($current)) {
            return false;
        }
        if ($desired === self::DISTANCE_UNLIMITED || (float) $current === (float) self::DISTANCE_UNLIMITED) {
            return (float) $current === (float) $desired;
        }

        return abs((float) $current - (float) $desired) <= $epsilon;
    }
}
