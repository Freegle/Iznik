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

    public function test_merge_recluster_rehomes_items_and_carries_stamps(): void
    {
        // Two enabled groups too far apart to cluster: two areas. gLow gets the
        // lower id so a merged cluster will anchor on it, replacing gHigh's area.
        $gLow = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        $gHigh = $this->createTestGroup(['lat' => 51.90, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        $svc = $this->svc();

        $svc->rebuildAreas();
        $this->assertSame(2, CommunityNewsArea::count());

        // Give gHigh's area history: an item linked to a ChitChat post, and stamps.
        $oldArea = CommunityNewsArea::where('anchorgroupid', $gHigh->id)->firstOrFail();
        $oldArea->update([
            'lastresearched' => now()->subHours(2),
            'lastposted' => now()->subHour(),
            'lastemailed' => now()->subDay(),
        ]);
        $item = \App\Models\CommunityNewsItem::create([
            'areaid' => $oldArea->id, 'title' => 'History', 'snippet' => 'Keep me.',
            'url' => 'https://example.org/h', 'researched_at' => now()->subHours(2),
            'newsfeedid' => 12345, 'posted_at' => now()->subHour(),
        ]);

        // A new group in between chains the two into one cluster (union-find).
        $this->createTestGroup(['lat' => 51.70, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        $svc->rebuildAreas();

        $this->assertSame(1, CommunityNewsArea::count());
        $merged = CommunityNewsArea::firstOrFail();
        $this->assertSame($gLow->id, (int) $merged->anchorgroupid);

        // The item survived, re-homed to the merged area, still linked to its post.
        $item->refresh();
        $this->assertSame($merged->id, (int) $item->areaid);
        $this->assertSame(12345, (int) $item->newsfeedid);

        // Cadence stamps carried forward (max of constituents), so the merged
        // area can't re-post or re-mail its members prematurely.
        $this->assertNotNull($merged->lastresearched);
        $this->assertNotNull($merged->lastposted);
        $this->assertNotNull($merged->lastemailed);
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
