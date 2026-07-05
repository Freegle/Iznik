<?php

namespace Tests\Feature\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Services\CommunityNews\CommunityNewsAreaService;
use Tests\TestCase;

class CommunityNewsAreaServiceTest extends TestCase
{
    private function svc(): CommunityNewsAreaService
    {
        return app(CommunityNewsAreaService::class);
    }

    public function test_clusters_nearby_and_separates_far(): void
    {
        // Two London groups ~1.7mi apart -> one area; Edinburgh ~330mi -> its own.
        $near1 = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        $near2 = $this->createTestGroup(['lat' => 51.52, 'lng' => -0.10, 'settings' => ['communitynews' => 1]]);
        $far   = $this->createTestGroup(['lat' => 55.95, 'lng' => -3.19, 'settings' => ['communitynews' => 1]]);
        // Not enabled -> must not appear in any area.
        $off   = $this->createTestGroup(['lat' => 51.51, 'lng' => -0.11]);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $this->assertSame(2, CommunityNewsArea::count());

        $london = CommunityNewsArea::where('anchorgroupid', min($near1->id, $near2->id))->first();
        $this->assertNotNull($london);
        $this->assertSame(2, $london->groupcount);
        $this->assertEqualsCanonicalizing([$near1->id, $near2->id], $london->groupids);

        $edinburgh = CommunityNewsArea::where('anchorgroupid', $far->id)->first();
        $this->assertNotNull($edinburgh);
        $this->assertSame(1, $edinburgh->groupcount);

        $allGroupIds = CommunityNewsArea::all()->flatMap(fn ($a) => $a->groupids)->all();
        $this->assertNotContains($off->id, $allGroupIds);
    }

    public function test_rebuild_is_idempotent_and_prunes_disabled(): void
    {
        $g = $this->createTestGroup(['lat' => 51.5, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        $svc = $this->svc();

        $svc->rebuildAreas();
        $this->assertSame(1, CommunityNewsArea::count());

        // Re-running upserts the same row, not a duplicate.
        $svc->rebuildAreas();
        $this->assertSame(1, CommunityNewsArea::count());

        // Turning the group off prunes its area (items cascade).
        $g->update(['settings' => ['communitynews' => 0]]);
        $svc->rebuildAreas();
        $this->assertSame(0, CommunityNewsArea::count());
    }

    public function test_area_name_strips_freegle_and_tags_multi(): void
    {
        $a = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        // Give it a Freegle-style display name to exercise the cleaner.
        $a->update(['namefull' => 'Edinburgh Freegle']);
        $b = $this->createTestGroup(['lat' => 51.505, 'lng' => -0.11, 'settings' => ['communitynews' => 1]]);
        $b->update(['namefull' => 'Leith Freegle']);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $area = CommunityNewsArea::first();
        $this->assertStringNotContainsStringIgnoringCase('freegle', $area->name);
        $this->assertStringContainsString('& nearby', $area->name);
    }

    public function test_haversine_london_to_edinburgh_is_about_330_miles(): void
    {
        $d = $this->svc()->haversineMiles(51.5074, -0.1278, 55.9533, -3.1883);
        $this->assertGreaterThan(320, $d);
        $this->assertLessThan(360, $d);
    }
}
