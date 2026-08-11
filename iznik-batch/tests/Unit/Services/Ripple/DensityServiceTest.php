<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\DensityService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DensityServiceTest extends TestCase
{
    private const K = 400;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freegle.ripple.max_minutes' => 30,
            'freegle.ripple.density.enabled' => true,
            'freegle.ripple.density.k' => self::K,
            'freegle.ripple.density.dense_max_miles' => 1.6,
            'freegle.ripple.density.medium_max_miles' => 3.1,
            'freegle.ripple.density.max_minutes.dense' => 20,
            'freegle.ripple.density.max_minutes.medium' => 30,
            'freegle.ripple.density.max_minutes.sparse' => 45,
        ]);
    }

    /**
     * $count freeglers, the furthest of them $miles away (north, so the great-circle
     * conversion is the simple one and the expected radius is unambiguous).
     */
    private function fakeKnn(int $count, float $miles, float $lat = 51.5, float $lng = -0.1): void
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            // Spread them between 0 and $miles so only the last one sets the radius.
            $offset = ($miles * ($i + 1) / $count) / 69.05; // degrees of latitude
            $results[] = ['id' => $i + 1, 'distance' => $offset, 'extra' => [
                'lat' => $lat + $offset, 'lng' => $lng,
            ]];
        }
        Http::fake(['*userapproxlocs/knn*' => Http::response(['results' => $results], 200)]);
    }

    public function test_a_tight_circle_round_the_nearest_400_is_a_city(): void
    {
        $this->fakeKnn(self::K, 1.0);

        $cap = app(DensityService::class)->capFor(51.5, -0.1);

        $this->assertSame(DensityService::BAND_DENSE, $cap['band']);
        $this->assertEqualsWithDelta(1.0, $cap['radius_miles'], 0.05);
        // Dense conversion collapses past 20-25 min, so the last third of a flat 30 buys
        // nothing but mail and crossposts.
        $this->assertSame(20.0, $cap['max_minutes']);
    }

    public function test_a_middling_circle_keeps_the_thirty_minutes_it_always_had(): void
    {
        $this->fakeKnn(self::K, 2.5);

        $cap = app(DensityService::class)->capFor(51.5, -0.1);

        $this->assertSame(DensityService::BAND_MEDIUM, $cap['band']);
        $this->assertSame(30.0, $cap['max_minutes']);
    }

    public function test_a_wide_circle_is_country_and_gets_longer(): void
    {
        $this->fakeKnn(self::K, 6.0);

        $cap = app(DensityService::class)->capFor(51.5, -0.1);

        $this->assertSame(DensityService::BAND_SPARSE, $cap['band']);
        // Sparse 30-45 min converts as well as sparse 0-10 min, and rural takers
        // routinely drive 20-30, so cutting at 30 drops willing takers.
        $this->assertSame(45.0, $cap['max_minutes']);
    }

    public function test_running_out_of_freeglers_before_k_is_the_sparsest_case_of_all(): void
    {
        // The KNN buffer ladder sweeps to its ceiling before giving up, so finding only
        // 40 people inside it means the true 400-radius is bigger than the ceiling.
        $this->fakeKnn(40, 8.0);

        $cap = app(DensityService::class)->capFor(51.5, -0.1);

        $this->assertSame(DensityService::BAND_SPARSE, $cap['band']);
        $this->assertSame(45.0, $cap['max_minutes']);
    }

    public function test_an_empty_answer_falls_back_to_the_flat_cap_rather_than_guessing(): void
    {
        // An empty index (mid-rebuild) is indistinguishable from empty countryside, and
        // stretching a London post to 45 minutes because a lookup came back blank is a
        // far worse failure than leaving it where it was.
        Http::fake(['*userapproxlocs/knn*' => Http::response(['results' => []], 200)]);

        $cap = app(DensityService::class)->capFor(51.5, -0.1);

        $this->assertSame(DensityService::BAND_UNKNOWN, $cap['band']);
        $this->assertNull($cap['radius_miles']);
        $this->assertSame(30.0, $cap['max_minutes']);
    }

    public function test_a_spatial_server_error_falls_back_to_the_flat_cap(): void
    {
        Http::fake(['*userapproxlocs/knn*' => Http::response('boom', 500)]);

        $cap = app(DensityService::class)->capFor(51.5, -0.1);

        $this->assertSame(DensityService::BAND_UNKNOWN, $cap['band']);
        $this->assertSame(30.0, $cap['max_minutes']);
    }

    public function test_the_killswitch_reverts_every_post_to_the_flat_cap(): void
    {
        config(['freegle.ripple.density.enabled' => false]);
        $this->fakeKnn(self::K, 1.0); // would be dense if anyone looked

        $cap = app(DensityService::class)->capFor(51.5, -0.1);

        $this->assertSame(DensityService::BAND_UNKNOWN, $cap['band']);
        $this->assertSame(30.0, $cap['max_minutes']);
        Http::assertNothingSent();
    }

    public function test_one_lookup_per_origin_however_many_posts_share_it(): void
    {
        // Co-located posts are common (the same poster from home, a shared postcode
        // centroid); the reach schedule is already deduped by origin and the density
        // measurement has to be too, or every batch multiplies spatial load.
        $this->fakeKnn(self::K, 1.0);
        $svc = app(DensityService::class);

        $svc->capFor(51.5, -0.1);
        $svc->capFor(51.5, -0.1);
        $svc->capFor(51.50001, -0.10001); // same 4dp origin

        Http::assertSentCount(1);
    }

    public function test_band_thresholds_are_inclusive_at_the_bottom_of_the_next_band(): void
    {
        // Pure, so the tercile boundaries can be re-cut against data without a server.
        $this->assertSame(DensityService::BAND_DENSE, DensityService::band(400, 400, 1.6));
        $this->assertSame(DensityService::BAND_MEDIUM, DensityService::band(400, 400, 1.61));
        $this->assertSame(DensityService::BAND_MEDIUM, DensityService::band(400, 400, 3.1));
        $this->assertSame(DensityService::BAND_SPARSE, DensityService::band(400, 400, 3.11));
        $this->assertSame(DensityService::BAND_UNKNOWN, DensityService::band(0, 400, null));
    }
}
