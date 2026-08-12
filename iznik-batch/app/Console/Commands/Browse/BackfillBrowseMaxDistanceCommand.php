<?php

namespace App\Console\Commands\Browse;

use App\Models\User;
use App\Services\Ripple\DensityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Put every member on the travel-time budget their own surroundings justify, and
 * keep settings.browseMaxDistance consistent with it.
 *
 * The "How far away" slider stores a travel-time budget in minutes (the source
 * of truth) and a derived crow-flies mile radius that the fast Haversine feed
 * filter and the digest DistancePreferenceFilter actually read. The slider used
 * to keep the OLD radius when the minutes->miles routing lookup failed, so the
 * pair could diverge: seen live as a slider showing 25 minutes while the feed
 * stayed capped at a stale 1 mile, which the member experienced as "I only see
 * old posts". The slider now fails open, but every already-diverged pair stays
 * wrong until recomputed - which is part of this command's job.
 *
 * The other part is the reason it now has to run for members who never touched
 * the slider at all. A post's ripple grows to the widest budget any band earns
 * (DensityService::ceiling()), because the cap belongs to the person who would
 * travel rather than to the item - see DensityService's docblock. That is only
 * safe if each member is then admitted on their OWN band, and the thing that
 * admits them is exactly this preference. On live only 2,900 of 121,000 recently
 * active members have ever set one, so without a materialised default the wider
 * ripple would show and mail a city member posts 45 minutes away. A member's
 * default is therefore their band's cap:
 *
 *   dense  -> 20 min      medium -> 30 min      sparse -> 45 min
 *
 * with the radius derived from real routing at that travel time.
 *
 * That default is written to settings.browseReachMaxDistance, NOT browseMaxDistance.
 * The latter is the member's own choice and ALSO caps how far away other people see
 * their posts, so a default written there would stop a city member's posts travelling
 * past their ~4.8 mile band radius - the exact opposite of the intent, which is to let
 * posts travel while holding each RECIPIENT to what their own surroundings justify. A
 * member who has set browseMaxDistance keeps having that key reconciled; nobody has one
 * created for them.
 *
 * An explicit narrower choice is kept, not overwritten - but it is RESCALED onto
 * the member's new range, because the old slider's positions meant a fixed 5-30. A
 * stored value said what FRACTION of the range the member wanted, not an absolute
 * travel time they would recognise: 15 was two fifths of the way up the old scale, and
 * two fifths of a rural member's 5-45 is 20. The rescale is proportional and snapped to
 * the slider's 5-minute step:
 *
 *   f = (old - 5) / (30 - 5), clamped to [0,1]
 *   new = round_to_5(5 + f * (cap - 5)), clamped to [5, cap]
 *
 * At or above the old top stop, f = 1 and the member lands on their new cap.
 *
 * The unlimited sentinel means "defer to the server's own reach", and that reach is
 * now the ceiling - so it only says "as far as my band goes" for a member whose band
 * earns it. Below the ceiling the top stop stores a real derived radius instead, or a
 * medium-band member would silently gain the widest band's reach.
 *
 * Never guesses: no known location, or a failed routing/density lookup, means
 * SKIPPED and untouched. A batch job must not loosen or tighten someone's feed on
 * a lookup blip.
 *
 * Users with a browseMaxDistance but NO minutes are pre-2026-07-10 miles-slider writes.
 * Since the slider went time-based it has shown them "no limit" while their old
 * cap silently kept filtering, so they are treated as being at the top stop and
 * land on their band's cap (decided 2026-08-04; the same rule, now band-aware).
 * An old app bundle that still runs the miles slider may re-write a cap; that
 * re-write is a current, deliberate act and stands.
 *
 * Cost. Both lookups are memoized on a ROUNDED location, so a street of members
 * costs one spatial call and one routing call between them rather than one each -
 * without which a 121,000-member pass would be a quarter of a million calls.
 * Neither density nor a travel-time radius varies meaningfully within the
 * rounding, and the density memo matches the 4dp key DensityService already uses.
 *
 * The selection is a full walk of users (~2.9M rows on live): neither the JSON_EXTRACT
 * predicates nor lastaccess is indexed, so chunkById pages by primary key and evaluates
 * the filter per row. That is acceptable for a manual, occasional pass and is why this is
 * not scheduled - run it off-peak, --dry-run first, and use --since-days to bound how many
 * members it will actually touch.
 *
 * Chunked by id and re-runnable: it only ever moves a member towards the
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

    /**
     * The INBOUND-ONLY band default. Separate from browseMaxDistance because that key is
     * the member's own choice AND caps how far away other people see their posts - so a
     * default written there would stop a city member's posts travelling past their ~4.8
     * mile band radius, which is the opposite of the intent. Must stay in step with
     * DistancePreferenceFilter::maxDistanceMiles and the Go resolveMaxDistance.
     */
    private const DEFAULT_KEY = 'browseReachMaxDistance';

    /**
     * The bounds the time-based slider had before it became band-aware. The old top
     * stop is what a stored value has to be read against to know what fraction of
     * their range the member was asking for. Must stay in step with
     * iznik-nuxt3/constants.js BROWSE_MINUTES_MIN / BROWSE_MINUTES_STEP.
     */
    private const MINUTES_MIN = 5;

    private const MINUTES_OLD_MAX = 30;

    private const MINUTES_STEP = 5;

    /** ~11m of latitude: far finer than density or a travel-time radius can resolve. */
    private const DENSITY_MEMO_DP = 4;

    /** ~110m: a radius in miles cannot tell two members this close apart. */
    private const RADIUS_MEMO_DP = 3;

    protected $signature = 'browse:backfill-max-distance
                            {--chunk=200 : Users per DB chunk}
                            {--limit=0 : Stop after this many corrections (0 = no limit)}
                            {--since-days=90 : Also give a default to members active within this many days (0 = only those who already have a setting)}
                            {--epsilon-miles=0.5 : Leave pairs alone when the recomputed radius is within this of the stored one}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Put each member on their own density band travel-time budget, and reconcile the derived browseMaxDistance';

    /** Memo: rounded "lat,lng" => density cap. */
    private array $capMemo = [];

    /** Memo: "lat,lng,minutes" => derived radius, or false when the lookup failed. */
    private array $radiusMemo = [];

    public function __construct(private ?DensityService $density = null)
    {
        parent::__construct();
        $this->density ??= new DensityService();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $sinceDays = max(0, (int) $this->option('since-days'));
        $epsilon = max(0.0, (float) $this->option('epsilon-miles'));
        $apiBase = rtrim(config('freegle.town_near_url'), '/');

        $stats = [
            'scanned' => 0,
            'corrected' => 0,
            'already_consistent' => 0,
            'no_location' => 0,
            'lookup_failed' => 0,
        ];

        $this->selection($sinceDays)
            ->orderBy('id')
            ->chunkById($chunk, function ($users) use ($dryRun, $limit, $epsilon, $apiBase, &$stats) {
                foreach ($users as $user) {
                    if ($limit > 0 && $stats['corrected'] >= $limit) {
                        return false;
                    }

                    $stats['scanned']++;
                    $this->reconcile($user, $dryRun, $epsilon, $apiBase, $stats);
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
     * Members needing a preference: anyone who already has one (to reconcile or
     * rescale), plus - unless --since-days=0 - anyone active recently enough to be
     * shown or mailed a post, who needs the band default materialised before the
     * wider ripple reaches them.
     */
    private function selection(int $sinceDays)
    {
        $query = User::query()->whereNull('deleted');

        // keep-raw: JSON_EXTRACT path predicates have no query-builder equivalent;
        // whereJsonContains tests containment, not presence of a key.
        $hasSetting = "(JSON_EXTRACT(settings, '$.browseMaxMinutes') IS NOT NULL OR JSON_EXTRACT(settings, '$.browseMaxDistance') IS NOT NULL)";

        if ($sinceDays === 0) {
            return $query->whereRaw($hasSetting);
        }

        return $query->where(function ($q) use ($hasSetting, $sinceDays) {
            $q->whereRaw($hasSetting)
                ->orWhere('lastaccess', '>=', now()->subDays($sinceDays));
        });
    }

    /** Move one member onto their band's budget, or leave them alone and say why. */
    private function reconcile(User $user, bool $dryRun, float $epsilon, string $apiBase, array &$stats): void
    {
        $settings = $user->settings ?? [];
        $minutes = isset($settings['browseMaxMinutes'])
            ? (int) $settings['browseMaxMinutes']
            : null;

        // A member who has set browseMaxDistance chose it, and that key also caps how far
        // away other people see THEIR posts. We reconcile it when they have one; we never
        // create one, because a created value would silently shrink their outbound reach.
        $chose = array_key_exists('browseMaxDistance', $settings);
        $targetKey = $chose ? 'browseMaxDistance' : self::DEFAULT_KEY;
        $current = $settings[$targetKey] ?? null;

        $loc = $this->location($user);
        if ($loc === null) {
            $stats['no_location']++;

            return;
        }

        $cap = $this->capFor($loc);
        if ($cap['band'] === DensityService::BAND_UNKNOWN) {
            // Density could not be measured. Their band is precisely what this command
            // exists to apply, so a guess here is the one thing worse than leaving them
            // alone - it would be a cap nobody chose.
            $stats['lookup_failed']++;

            return;
        }

        $capMinutes = (int) round($cap['max_minutes']);
        $desiredMinutes = $this->rescale($minutes, $capMinutes);
        $desired = $this->desiredDistance($loc, $desiredMinutes, $capMinutes, $apiBase);
        if ($desired === null) {
            $stats['lookup_failed']++;

            return;
        }

        if ($desiredMinutes === $minutes && $this->consistent($current, $desired, $epsilon)) {
            $stats['already_consistent']++;

            return;
        }

        $this->line(sprintf(
            '%suser %d: %s band, minutes %s -> %d (cap %d), %s %s -> %s',
            $dryRun ? '[dry-run] ' : '',
            $user->id,
            $cap['band'],
            $minutes === null ? 'NULL' : (string) $minutes,
            $desiredMinutes,
            $capMinutes,
            $targetKey,
            $current === null ? 'NULL' : (string) $current,
            (string) $desired,
        ));

        if (! $dryRun) {
            $settings['browseMaxMinutes'] = $desiredMinutes;
            $settings[$targetKey] = $desired;
            $user->settings = $settings;
            $user->save();
        }
        $stats['corrected']++;
    }

    /**
     * Where the member's stored position lands on their own range.
     *
     * The stored minutes were chosen against a fixed 5-30 slider, so they say what
     * FRACTION of the available range the member wanted, not an absolute travel time
     * they would recognise. Carrying the number across unchanged would silently narrow
     * a rural member (25 of 30 was near their maximum; 25 of 45 is barely past the
     * middle) and would leave a city member above a cap the reach engine no longer
     * honours.
     *
     * No stored minutes means the top stop - see the class docblock.
     */
    public function rescale(?int $minutes, int $capMinutes): int
    {
        if ($minutes === null || $minutes >= self::MINUTES_OLD_MAX) {
            return $capMinutes;
        }

        $fraction = ($minutes - self::MINUTES_MIN) / (self::MINUTES_OLD_MAX - self::MINUTES_MIN);
        $fraction = max(0.0, min(1.0, $fraction));

        $scaled = self::MINUTES_MIN + $fraction * ($capMinutes - self::MINUTES_MIN);
        $snapped = (int) (round($scaled / self::MINUTES_STEP) * self::MINUTES_STEP);

        return max(self::MINUTES_MIN, min($capMinutes, $snapped));
    }

    /** The member's location, or null when there isn't one to work from. */
    private function location(User $user): ?object
    {
        if ($user->lastlocation === null) {
            return null;
        }

        $loc = DB::table('locations')
            ->where('id', $user->lastlocation)
            ->select('lat', 'lng')
            ->first();

        if (! $loc || $loc->lat === null || $loc->lng === null) {
            return null;
        }

        return $loc;
    }

    /** The band cap here, memoized so neighbours share one spatial call. */
    private function capFor(object $loc): array
    {
        $key = round((float) $loc->lat, self::DENSITY_MEMO_DP)
            . ',' . round((float) $loc->lng, self::DENSITY_MEMO_DP);

        return $this->capMemo[$key] ??= $this->density->capFor((float) $loc->lat, (float) $loc->lng);
    }

    /**
     * The distance the invariant wants, or null when it cannot be determined
     * honestly (a routing failure) and the member must be left alone.
     */
    private function desiredDistance(object $loc, int $minutes, int $capMinutes, string $apiBase): int|float|null
    {
        // The "no limit" sentinel defers to the server's own reach, and the server's
        // own reach is now the CEILING - so it only means "my band's worth" for a
        // member whose band earns the ceiling. For anyone below it, storing the
        // sentinel would hand them the widest band's reach: a Peterborough member
        // would go from 30 minutes to everything within 45 minutes of a post, which
        // on live is a 33-mile radius. Below the ceiling the band cap has to be a real
        // derived radius, which is exactly what holds each member to their own band.
        if ($minutes >= $capMinutes && $capMinutes >= (int) round(DensityService::ceiling())) {
            return self::DISTANCE_UNLIMITED;
        }

        $key = round((float) $loc->lat, self::RADIUS_MEMO_DP)
            . ',' . round((float) $loc->lng, self::RADIUS_MEMO_DP) . ',' . $minutes;

        if (! array_key_exists($key, $this->radiusMemo)) {
            $this->radiusMemo[$key] = $this->lookupRadius($loc, $minutes, $apiBase);
        }

        return $this->radiusMemo[$key] === false ? null : $this->radiusMemo[$key];
    }

    /** One town/near call: the crow-flies radius this travel time reaches, or false. */
    private function lookupRadius(object $loc, int $minutes, string $apiBase): float|false
    {
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

        return is_numeric($radius) && $radius > 0 ? (float) $radius : false;
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
