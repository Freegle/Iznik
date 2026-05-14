<?php

namespace Tests\Unit\Services;

use App\Services\WhatJobsService;
use Tests\TestCase;

/**
 * Unit tests for WhatJobs geocoding improvements.
 *
 * All geocoding is mocked — we test the logic in geocodeCityState
 * by injecting a fake geocodeAddress implementation.
 *
 * Improvement targets:
 *  1. Outward postcode → geocode via postcode field
 *  2. Title-case city before geocoding
 *  3. Combined "city, county" query as fallback
 *  4. Slash-list cities: try each part
 *  5. State abbreviations (eng, wls, sct, nir) treated as bad states
 *  6. State exact-name-match fix: return result when geocoder confirms state
 */
class WhatJobsGeocodingTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a service stub where geocodeAddress() is replaced by a
     * simple array lookup, keyed on the query string.
     *
     * The lookup value is either:
     *  - a 4-element bbox array [swlat, swlng, nelat, nelng]  (→ returns full 5-element result)
     *  - null (→ geocoder found nothing)
     *
     * $dbCache optionally pre-seeds the in-memory geocode cache.
     */
    private function makeService(array $geocodeLookup, array $dbCache = []): WhatJobsService
    {
        $bbox = fn(string $q) => $geocodeLookup[$q] ?? null;

        return new class($bbox, $dbCache) extends WhatJobsService {
            public function __construct(private \Closure $geocodeLookup, private array $seedCache) {}

            protected function geocodeAddress(
                string $addr,
                bool $allowPoint,
                bool $exact,
                float $bbswlat = WhatJobsService::UK_SWLAT,
                float $bbswlng = WhatJobsService::UK_SWLNG,
                float $bbnelat = WhatJobsService::UK_NELAT,
                float $bbnelng = WhatJobsService::UK_NELNG
            ): ?array {
                $bbox = ($this->geocodeLookup)($addr);
                if ($bbox === null) {
                    return null;
                }
                [$swlat, $swlng, $nelat, $nelng] = $bbox;
                return [$swlat, $swlng, $nelat, $nelng, WhatJobsService::boxPoly($swlat, $swlng, $nelat, $nelng)];
            }

            // Pre-populate in-memory cache before the parent's DB lookup runs
            public function geocodeCityState(string $city, string $state, string $country, array &$cache, string $zip = ''): ?array
            {
                foreach ($this->seedCache as $key => $val) {
                    $cache[$key] = $val;
                }
                return parent::geocodeCityState($city, $state, $country, $cache, $zip);
            }
        };
    }

    // ------------------------------------------------------------------
    // 1. State abbreviations treated as bad states
    // ------------------------------------------------------------------

    public function test_state_abbreviation_eng_is_treated_as_bad_state(): void
    {
        // 'eng' should be ignored (same as 'england'), so we fall back to city-only
        $svc   = $this->makeService(['London' => [51.3, -0.5, 51.7, 0.3]]);
        $cache = [];
        $result = $svc->geocodeCityState('London', 'eng', 'United Kingdom', $cache);
        $this->assertNotNull($result, 'Should geocode London even with bad state abbreviation');
    }

    public function test_state_abbreviation_wls_is_treated_as_bad_state(): void
    {
        $svc   = $this->makeService(['Cardiff' => [51.4, -3.2, 51.5, -3.1]]);
        $cache = [];
        $result = $svc->geocodeCityState('Cardiff', 'wls', 'United Kingdom', $cache);
        $this->assertNotNull($result);
    }

    public function test_state_abbreviation_sct_is_treated_as_bad_state(): void
    {
        $svc   = $this->makeService(['Edinburgh' => [55.9, -3.3, 56.0, -3.1]]);
        $cache = [];
        $result = $svc->geocodeCityState('Edinburgh', 'sct', 'United Kingdom', $cache);
        $this->assertNotNull($result);
    }

    public function test_state_abbreviation_nir_is_treated_as_bad_state(): void
    {
        $svc   = $this->makeService(['Belfast' => [54.5, -5.9, 54.6, -5.9]]);
        $cache = [];
        $result = $svc->geocodeCityState('Belfast', 'nir', 'United Kingdom', $cache);
        $this->assertNotNull($result);
    }

    // ------------------------------------------------------------------
    // 2. Title-case city before geocoding
    //    Feed sends 'sudbury' but geocoder may only return 'Sudbury'
    // ------------------------------------------------------------------

    public function test_title_cased_city_geocodes_when_lowercase_fails(): void
    {
        // Geocoder returns result for 'Sudbury' but not 'sudbury'
        $svc   = $this->makeService(['Sudbury' => [52.0, 0.72, 52.1, 0.74]]);
        $cache = [];
        $result = $svc->geocodeCityState('sudbury', '', 'United Kingdom', $cache);
        $this->assertNotNull($result, 'Should try title-cased city when lowercase fails');
    }

    public function test_lowercase_city_still_works_when_geocoder_accepts_lowercase(): void
    {
        // Geocoder accepts both cases
        $svc   = $this->makeService([
            'sudbury' => [52.0, 0.72, 52.1, 0.74],
            'Sudbury' => [52.0, 0.72, 52.1, 0.74],
        ]);
        $cache = [];
        $result = $svc->geocodeCityState('sudbury', '', 'United Kingdom', $cache);
        $this->assertNotNull($result);
    }

    // ------------------------------------------------------------------
    // 3. "city, county" combined query as fallback
    //    Helps Photon disambiguate e.g. "Coombe, Cornwall"
    // ------------------------------------------------------------------

    public function test_combined_city_county_query_used_as_fallback(): void
    {
        // City alone fails, but "Kenwyn, Cornwall" works
        $svc   = $this->makeService(['Kenwyn, Cornwall' => [50.27, -5.12, 50.28, -5.11]]);
        $cache = [];
        $result = $svc->geocodeCityState('Kenwyn', 'Cornwall', 'United Kingdom', $cache);
        $this->assertNotNull($result, 'Should try "city, county" combined query');
    }

    public function test_combined_query_not_tried_when_city_alone_succeeds(): void
    {
        // City alone succeeds — combined query should NOT be called
        $called = [];
        $svc = new class($called) extends WhatJobsService {
            public function __construct(private array &$log) {}
            protected function geocodeAddress(
                string $addr, bool $allowPoint, bool $exact,
                float $bbswlat = WhatJobsService::UK_SWLAT,
                float $bbswlng = WhatJobsService::UK_SWLNG,
                float $bbnelat = WhatJobsService::UK_NELAT,
                float $bbnelng = WhatJobsService::UK_NELNG
            ): ?array {
                $this->log[] = $addr;
                if ($addr === 'Leeds') {
                    return [53.8, -1.6, 53.9, -1.5, WhatJobsService::boxPoly(53.8, -1.6, 53.9, -1.5)];
                }
                return null;
            }
        };

        $cache = [];
        $result = $svc->geocodeCityState('Leeds', 'West Yorkshire', 'United Kingdom', $cache);
        $this->assertNotNull($result);
        $this->assertNotContains('Leeds, West Yorkshire', $called, 'Combined query should not be tried when city alone works');
    }

    // ------------------------------------------------------------------
    // 4. Slash-list cities: try each segment
    //    Feed sends "Idless / Malpas / Kea / Gloweth"
    // ------------------------------------------------------------------

    public function test_slash_city_first_part_geocodes(): void
    {
        // "Idless" succeeds
        $svc   = $this->makeService(['Idless' => [50.28, -5.06, 50.29, -5.05]]);
        $cache = [];
        $result = $svc->geocodeCityState(
            'Idless / Malpas / Kea / Gloweth',
            'South West',
            'United Kingdom',
            $cache
        );
        $this->assertNotNull($result, 'Should try first part of slash-separated city list');
    }

    public function test_slash_city_second_part_geocodes_when_first_fails(): void
    {
        // "Idless" fails, "Malpas" succeeds
        $svc   = $this->makeService(['Malpas' => [50.27, -5.07, 50.28, -5.06]]);
        $cache = [];
        $result = $svc->geocodeCityState(
            'Idless / Malpas / Kea',
            '',
            'United Kingdom',
            $cache
        );
        $this->assertNotNull($result, 'Should try subsequent parts of slash-separated city list');
    }

    public function test_single_city_without_slash_not_split(): void
    {
        $svc   = $this->makeService(['Truro' => [50.26, -5.06, 50.27, -5.05]]);
        $cache = [];
        $result = $svc->geocodeCityState('Truro', '', 'United Kingdom', $cache);
        $this->assertNotNull($result);
    }

    // ------------------------------------------------------------------
    // 5. State-constrained city lookup: use state bbox even when state
    //    name exactly matches geocoder (previously returned null due to
    //    inverted nameMatches logic)
    // ------------------------------------------------------------------

    public function test_state_bbox_used_for_city_when_state_name_matches_exactly(): void
    {
        // State 'Cornwall' geocodes to a bbox, city 'Kenwyn' is within it
        // Previously this failed because exact-match with same name returned null
        $svc = new class extends WhatJobsService {
            protected function geocodeAddress(
                string $addr, bool $allowPoint, bool $exact,
                float $bbswlat = WhatJobsService::UK_SWLAT,
                float $bbswlng = WhatJobsService::UK_SWLNG,
                float $bbnelat = WhatJobsService::UK_NELAT,
                float $bbnelng = WhatJobsService::UK_NELNG
            ): ?array {
                if ($addr === 'Cornwall') {
                    // State bbox — Cornwall
                    return [49.9, -5.8, 50.9, -4.2, WhatJobsService::boxPoly(49.9, -5.8, 50.9, -4.2)];
                }
                if ($addr === 'Kenwyn' && $bbswlat > 49.0) {
                    // City within Cornwall bbox
                    return [50.27, -5.12, 50.28, -5.11, WhatJobsService::boxPoly(50.27, -5.12, 50.28, -5.11)];
                }
                return null;
            }
        };

        $cache  = [];
        $result = $svc->geocodeCityState('Kenwyn', 'Cornwall', 'United Kingdom', $cache);
        $this->assertNotNull($result, 'State bbox should be used even when state name exactly matches geocoder name');
    }

    // ------------------------------------------------------------------
    // 6. Outward postcode geocoding
    // ------------------------------------------------------------------

    public function test_outward_postcode_geocodes_city_that_would_otherwise_fail(): void
    {
        // City 'kenwyn' fails alone; outward code 'TR1' also fails in Photon
        // but postcode lookup gives a real location
        $svc = $this->makeService([]); // no Photon results at all

        // Override geocodeByPostcode to return a real result for TR1
        $svc2 = new class extends WhatJobsService {
            protected function geocodeAddress(
                string $addr, bool $allowPoint, bool $exact,
                float $bbswlat = WhatJobsService::UK_SWLAT,
                float $bbswlng = WhatJobsService::UK_SWLNG,
                float $bbnelat = WhatJobsService::UK_NELAT,
                float $bbnelng = WhatJobsService::UK_NELNG
            ): ?array {
                return null; // Photon knows nothing
            }

            protected function geocodePostcode(string $outward): ?array
            {
                if ($outward === 'TR1') {
                    return [50.25, -5.08, 50.27, -5.05, WhatJobsService::boxPoly(50.25, -5.08, 50.27, -5.05)];
                }
                return null;
            }
        };

        $cache  = [];
        $result = $svc2->geocodeCityState('kenwyn', 'South West', 'United Kingdom', $cache, 'TR1');
        $this->assertNotNull($result, 'Should geocode via outward postcode when Photon fails');
    }

    public function test_postcode_not_used_when_city_geocodes_fine(): void
    {
        // City geocodes fine; postcode should not be consulted
        $postcodeCalled = false;
        $svc = new class($postcodeCalled) extends WhatJobsService {
            public function __construct(private bool &$flag) {}
            protected function geocodeAddress(
                string $addr, bool $allowPoint, bool $exact,
                float $bbswlat = WhatJobsService::UK_SWLAT,
                float $bbswlng = WhatJobsService::UK_SWLNG,
                float $bbnelat = WhatJobsService::UK_NELAT,
                float $bbnelng = WhatJobsService::UK_NELNG
            ): ?array {
                if ($addr === 'Leeds') {
                    return [53.8, -1.6, 53.9, -1.5, WhatJobsService::boxPoly(53.8, -1.6, 53.9, -1.5)];
                }
                return null;
            }
            protected function geocodePostcode(string $outward): ?array
            {
                $this->flag = true;
                return [53.8, -1.55, 53.82, -1.52, WhatJobsService::boxPoly(53.8, -1.55, 53.82, -1.52)];
            }
        };

        $cache  = [];
        $result = $svc->geocodeCityState('Leeds', 'West Yorkshire', 'United Kingdom', $cache, 'LS1 1AA');
        $this->assertNotNull($result);
        $this->assertFalse($postcodeCalled, 'geocodePostcode should not be called when city geocodes successfully');
    }

    // ------------------------------------------------------------------
    // 7. geocodeCityState signature now accepts optional $zip parameter
    // ------------------------------------------------------------------

    public function test_geocode_city_state_accepts_zip_parameter(): void
    {
        $svc   = $this->makeService(['Manchester' => [53.4, -2.3, 53.5, -2.2]]);
        $cache = [];
        // Should not throw even with zip parameter
        $result = $svc->geocodeCityState('Manchester', 'North West', 'United Kingdom', $cache, 'M1 1AA');
        $this->assertNotNull($result);
    }

    // ------------------------------------------------------------------
    // 8. Regression: existing cases must still pass
    // ------------------------------------------------------------------

    public function test_guernsey_country_returns_null(): void
    {
        $svc   = $this->makeService(['St Peter Port' => [49.4, -2.5, 49.5, -2.4]]);
        $cache = [];
        $result = $svc->geocodeCityState('St Peter Port', '', 'Guernsey', $cache);
        $this->assertNull($result);
    }

    public function test_bad_city_returns_null(): void
    {
        $svc   = $this->makeService([]);
        $cache = [];
        $result = $svc->geocodeCityState('home based', '', 'United Kingdom', $cache);
        $this->assertNull($result);
    }

    public function test_bad_state_england_skipped(): void
    {
        // 'england' is a bad state; should not be geocoded, falls back to city
        $svc   = $this->makeService(['London' => [51.3, -0.5, 51.7, 0.3]]);
        $cache = [];
        $result = $svc->geocodeCityState('London', 'england', 'United Kingdom', $cache);
        $this->assertNotNull($result, 'Should still geocode city when state is England');
    }

    public function test_cache_hit_returns_immediately(): void
    {
        $svc   = $this->makeService([]); // geocoder returns nothing
        $cache = [];
        $bbox  = [53.8, -1.6, 53.9, -1.5, WhatJobsService::boxPoly(53.8, -1.6, 53.9, -1.5)];

        // Pre-populate cache
        $svc->geocodeCityState('Leeds', 'West Yorkshire', 'United Kingdom', $cache);
        // Inject cached result manually (simulating earlier hit)
        $cache['Leeds,West Yorkshire,United Kingdom'] = $bbox;

        $result = $svc->geocodeCityState('Leeds', 'West Yorkshire', 'United Kingdom', $cache);
        $this->assertSame($bbox, $result);
    }

    public function test_city_too_coarse_returns_null(): void
    {
        // Geocoder returns a huge bbox (area > 50) for the city
        $svc = new class extends WhatJobsService {
            protected function geocodeAddress(
                string $addr, bool $allowPoint, bool $exact,
                float $bbswlat = WhatJobsService::UK_SWLAT,
                float $bbswlng = WhatJobsService::UK_SWLNG,
                float $bbnelat = WhatJobsService::UK_NELAT,
                float $bbnelng = WhatJobsService::UK_NELNG
            ): ?array {
                // Huge bbox (e.g. entire England)
                return [-7.5, 49.9, 1.7, 58.6, WhatJobsService::boxPoly(-7.5, 49.9, 1.7, 58.6)];
            }
        };
        $cache  = [];
        $result = $svc->geocodeCityState('England', '', 'United Kingdom', $cache);
        $this->assertNull($result, 'City bbox too coarse (area > 50) should be rejected');
    }
}
