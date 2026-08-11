<?php

namespace App\Services\Ripple;

use App\Support\GreatCircle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * How thinly freeglers are spread around a point, and therefore how far someone
 * there would travel for a free item.
 *
 * A single flat drive-time cap cannot fit the whole country. The chance that a
 * reply from drive-time D goes on to collect, measured over 887 posts split by
 * local freegler density:
 *
 *   drive-time of replier   dense   medium   sparse
 *   20-25 min                 15%      16%      29%
 *   25-30 min                  7%      12%      19%
 *   30-45 min                  7%      11%      20%
 *
 * In dense areas conversion collapses past ~20-25 minutes - there is always
 * someone closer, so a reply from half an hour away almost never becomes a
 * collection. In sparse areas there is no drop-off at all in the measurable
 * range: a countryside reply from 30-45 minutes out converts as well as a local
 * one (20% vs 18%), and rural takers routinely drive 20-30 minutes. A flat cap is
 * therefore simultaneously too generous in cities (the last 5-10 minutes buys
 * almost nothing but costs mail and crossposting) and too tight in the country (it
 * cuts off people who demonstrably do collect).
 *
 * WHOSE density decides. Read the table again by row and it is about the person
 * who would make the journey, not the item: it is the REPLIER's drive-time and
 * the REPLIER's surroundings that predict a collection. Applying the cap at the
 * post's origin gets this right only when both ends sit in the same kind of
 * place. Where they differ it fails exactly backwards, and the case it fails on
 * is the common one: a rural member's nearest town is the town they already
 * drive to, and its posts are the ones they most want. A member outside Spalding
 * (nearest 400 freeglers 16 miles out - definitively sparse) sits 38 minutes from
 * Peterborough, which is medium and so caps at 30. Sizing at the origin means
 * they can never be reached by the town's posts however far they would travel,
 * while a Peterborough member who would not leave the city is reached by
 * everything within 30 minutes of it.
 *
 * So the cap is applied to the RECIPIENT: a post's ripple grows to the widest
 * budget any member might justify (Ceiling()), and each member is then admitted
 * on their OWN band, via the travel-time preference that already gates browse and
 * mail: settings.browseMaxMinutes, plus the radius in browseReachMaxDistance - an
 * INBOUND-ONLY key, because browseMaxDistance also caps how far away others see the
 * member's OWN posts and defaulting it would stop a city member's posts travelling.
 *
 * The dense saving is preserved - a city member is still not shown or mailed a post
 * 40 minutes away, because their own band says 20 - while the rural member finally
 * gets the town. See docs/developers/reference/rippling-algorithm.md.
 *
 * Density is the radius of the circle that holds the nearest K freeglers (K=400)
 * to the point. Distance is great-circle miles, computed here rather than taken
 * from the spatial server's `distance` field, which is a Euclidean distance in
 * DEGREES and so understates east-west separation by about a third at UK
 * latitudes.
 *
 * Fails soft in every direction: an unreachable spatial server, an empty result or
 * the killswitch all give band 'unknown' and the flat cap. A cap must never fail
 * to a value nobody chose.
 */
class DensityService
{
    public const BAND_DENSE = 'dense';
    public const BAND_MEDIUM = 'medium';
    public const BAND_SPARSE = 'sparse';

    /** No usable measurement: the flat cap applies and the row says so. */
    public const BAND_UNKNOWN = 'unknown';

    private string $url;

    /** Per-run memo, keyed on the (already blurred) origin - one KNN call per origin. */
    private array $memo = [];

    public function __construct()
    {
        $this->url = rtrim(config('freegle.spatial_server_url', 'http://localhost:8194'), '/');
    }

    /**
     * The widest budget any band can earn. A ripple grows to this, because the cap
     * belongs to the recipient and a post cannot know in advance which bands the
     * members around it fall in - only that the sparse ones travel furthest. Members
     * nearer the middle are then held to their own band on the way out.
     *
     * Taken as the max over the configured bands rather than hardcoding 'sparse', so
     * re-tuning a band cannot silently leave the ripple too small to serve it.
     */
    public static function ceiling(): float
    {
        $flat = (float) config('freegle.ripple.max_minutes', 30);

        if (!config('freegle.ripple.density.enabled', true)) {
            return $flat;
        }

        $bands = (array) config('freegle.ripple.density.max_minutes', []);

        return max($flat, ...array_map('floatval', array_values($bands) ?: [$flat]));
    }

    /**
     * The travel-time budget someone at this point would justify, plus the
     * measurement behind it so every decision can be read back against its reason.
     *
     * Used for the RECIPIENT (their slider default and the reach they are admitted
     * on) and, for the post's origin, recorded on the reach row as the local
     * description - not as its growth limit, which is ceiling(). See the class
     * docblock for why the cap belongs to the traveller.
     *
     * @return array{band:string, radius_miles:?float, max_minutes:float}
     */
    public function capFor(float $lat, float $lng): array
    {
        $flat = (float) config('freegle.ripple.max_minutes', 30);

        if (!config('freegle.ripple.density.enabled', true)) {
            return ['band' => self::BAND_UNKNOWN, 'radius_miles' => null, 'max_minutes' => $flat];
        }

        $key = round($lat, 4) . ',' . round($lng, 4);
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $k = max(1, (int) config('freegle.ripple.density.k', 400));
        $measured = $this->measure($lat, $lng, $k);
        $band = self::band($measured['found'], $k, $measured['radius_miles']);
        $cap = $band === self::BAND_UNKNOWN
            ? $flat
            : (float) config("freegle.ripple.density.max_minutes.{$band}", $flat);

        return $this->memo[$key] = [
            'band' => $band,
            'radius_miles' => $measured['radius_miles'],
            'max_minutes' => $cap,
        ];
    }

    /**
     * Which band a nearest-K measurement falls in. Pure, so the policy is testable
     * without a spatial server.
     *
     * Nothing found (or no answer at all) is 'unknown', NOT sparse. An empty result
     * and an empty index look identical from here, and quietly stretching a London
     * post's reach to 45 minutes because the spatial index was mid-rebuild is a far
     * worse failure than leaving that post on the flat cap.
     *
     * Some found but fewer than K IS sparse, and confidently so: the KNN buffer ladder
     * sweeps out to its ceiling (~13-22 miles at UK latitudes) before giving up, so
     * failing to find K inside that means the true K-radius is bigger than the ceiling
     * - far past the sparse threshold whatever the exact figure. $furthestMiles is
     * then a lower bound on the radius rather than the radius, which is why the band
     * is decided by the shortfall and not by the number.
     */
    public static function band(int $found, int $k, ?float $furthestMiles): string
    {
        if ($found <= 0 || $furthestMiles === null) {
            return self::BAND_UNKNOWN;
        }
        if ($found < $k) {
            return self::BAND_SPARSE;
        }

        $dense = (float) config('freegle.ripple.density.dense_max_miles', 1.6);
        $medium = (float) config('freegle.ripple.density.medium_max_miles', 3.1);

        if ($furthestMiles <= $dense) {
            return self::BAND_DENSE;
        }

        return $furthestMiles <= $medium ? self::BAND_MEDIUM : self::BAND_SPARSE;
    }

    /**
     * Ask the spatial server for the nearest $k freeglers and report how many came
     * back and how far the furthest of them is.
     *
     * @return array{found:int, radius_miles:?float}
     */
    public function measure(float $lat, float $lng, int $k): array
    {
        $none = ['found' => 0, 'radius_miles' => null];
        $timeout = (int) config('freegle.ripple.density.timeout', 5);

        try {
            $response = Http::timeout($timeout)->get("{$this->url}/v1/userapproxlocs/knn", [
                'lat' => $lat,
                'lng' => $lng,
                'limit' => $k,
            ]);
        } catch (\Throwable $e) {
            Log::warning("ripple: density knn failed: {$e->getMessage()}", ['lat' => $lat, 'lng' => $lng]);

            return $none;
        }

        if (!$response->successful()) {
            Log::warning("ripple: density knn HTTP {$response->status()}", ['lat' => $lat, 'lng' => $lng]);

            return $none;
        }

        $results = $response->json('results') ?? [];
        if (empty($results)) {
            return $none;
        }

        // The server ranks by Euclidean degrees, which at UK latitudes squashes
        // east-west distance by ~cos(lat). The set of K it returns is therefore a
        // near-miss for the true nearest K, but the RADIUS must still be honest, so
        // take the furthest of them by great-circle distance.
        $furthest = 0.0;
        foreach ($results as $r) {
            $rLat = $r['extra']['lat'] ?? null;
            $rLng = $r['extra']['lng'] ?? null;
            if ($rLat === null || $rLng === null) {
                return $none; // an older spatial build without coordinates: do not guess
            }
            $furthest = max($furthest, GreatCircle::distanceMiles($lat, $lng, (float) $rLat, (float) $rLng));
        }

        return ['found' => count($results), 'radius_miles' => $furthest];
    }
}
