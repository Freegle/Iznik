<?php

namespace Tests\Feature\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Services\CommunityNews\CommunityNewsAreaService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityNewsAreaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Community News flipped to opt-OUT (2026-08-07): any group without an
        // explicit falsy flag takes part. These tests reason about the exact
        // set of areas their own groups produce, so the base-seeded groups the
        // suite ships with must be explicitly opted out or they flood every
        // count (31 areas where a test built 2). Each test then opts its own
        // groups in or out deliberately.
        DB::statement(
            "UPDATE `groups` SET settings = JSON_SET(COALESCE(settings, '{}'), '$.communitynews', 0)"
        );
    }

    private function svc(): CommunityNewsAreaService
    {
        return app(CommunityNewsAreaService::class);
    }

    private function town(string $name, float $lat, float $lng): int
    {
        return (int) DB::table('towns')->insertGetId(['name' => $name, 'lat' => $lat, 'lng' => $lng]);
    }

    public function test_groups_assign_to_nearest_town(): void
    {
        // Areas are anchored on the towns table: each enabled group joins its
        // nearest town (within the cap), and the town's name/centre become the
        // area's — a real, searchable place, immune to union-find chaining.
        $this->town('London', 51.5074, -0.1278);
        $this->town('Edinburgh', 55.9533, -3.1883);

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
        $this->assertSame('London', $london->name);
        $this->assertSame(2, $london->groupcount);
        $this->assertEqualsCanonicalizing([$near1->id, $near2->id], $london->groupids);
        // Area centre is the TOWN centre (the searchable anchor), not a group average.
        $this->assertEqualsWithDelta(51.5074, (float) $london->lat, 0.001);
        $this->assertEqualsWithDelta(-0.1278, (float) $london->lng, 0.001);

        $edinburgh = CommunityNewsArea::where('anchorgroupid', $far->id)->first();
        $this->assertNotNull($edinburgh);
        $this->assertSame('Edinburgh', $edinburgh->name);
        $this->assertSame(1, $edinburgh->groupcount);

        $allGroupIds = CommunityNewsArea::all()->flatMap(fn ($a) => $a->groupids)->all();
        $this->assertNotContains($off->id, $allGroupIds);
    }

    public function test_group_beyond_cap_stands_alone_named_from_group(): void
    {
        // Nearest town is ~46mi away — beyond the 20mi cap, so the group is its
        // own area, named from the group (stripped of "Freegle"), centred on it.
        $this->town('Norwich', 52.6309, 1.2974);

        $g = $this->createTestGroup(['lat' => 52.2053, 'lng' => 0.1218, 'settings' => ['communitynews' => 1]]);
        $g->update(['namefull' => 'Cambridge Freegle']);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $this->assertSame(1, CommunityNewsArea::count());
        $area = CommunityNewsArea::first();
        $this->assertSame($g->id, $area->anchorgroupid);
        $this->assertStringNotContainsStringIgnoringCase('freegle', $area->name);
        $this->assertStringContainsStringIgnoringCase('Cambridge', $area->name);
        $this->assertEqualsWithDelta(52.2053, (float) $area->lat, 0.001);
    }

    public function test_no_towns_every_group_stands_alone(): void
    {
        // Dev/empty-towns fallback: no chaining substitute, one area per group.
        $a = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        $b = $this->createTestGroup(['lat' => 51.505, 'lng' => -0.11, 'settings' => ['communitynews' => 1]]);

        $this->svc()->rebuildAreas();

        $this->assertSame(2, CommunityNewsArea::count());
        $this->assertNotNull(CommunityNewsArea::where('anchorgroupid', $a->id)->first());
        $this->assertNotNull(CommunityNewsArea::where('anchorgroupid', $b->id)->first());
    }

    public function test_groups_are_in_by_default_and_can_opt_out(): void
    {
        // 2026-08-07: Community News flipped from opt-in to opt-OUT. A group
        // that has never touched the setting takes part; only an explicit
        // falsy flag opts out.
        $this->town('London', 51.5074, -0.1278);
        $g = $this->createTestGroup(['lat' => 51.5, 'lng' => -0.12]);
        $svc = $this->svc();

        $svc->rebuildAreas();
        $this->assertSame(1, CommunityNewsArea::count(), 'an unset flag means IN under opt-out');

        $g->update(['settings' => ['communitynews' => 0]]);
        $svc->rebuildAreas();
        $this->assertSame(0, CommunityNewsArea::count(), 'an explicit 0 still opts out');
    }

    public function test_rebuild_is_idempotent_and_prunes_disabled(): void
    {
        $this->town('London', 51.5074, -0.1278);
        $g = $this->createTestGroup(['lat' => 51.5, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        $svc = $this->svc();

        $svc->rebuildAreas();
        $this->assertSame(1, CommunityNewsArea::count());
        $first = CommunityNewsArea::first();

        // Re-running upserts the same row (cadence timers survive), not a duplicate.
        $svc->rebuildAreas();
        $this->assertSame(1, CommunityNewsArea::count());
        $this->assertSame($first->id, CommunityNewsArea::first()->id);

        // Turning the group off prunes its area (items cascade).
        $g->update(['settings' => ['communitynews' => 0]]);
        $svc->rebuildAreas();
        $this->assertSame(0, CommunityNewsArea::count());
    }

    public function test_reshaped_area_rehomes_items_and_carries_stamps(): void
    {
        // No towns yet: two enabled groups stand alone as two areas. gLow gets
        // the lower id, so once a town captures both, the merged town area
        // anchors on it and gHigh's area becomes stale.
        $gLow = $this->createTestGroup(['lat' => 51.50, 'lng' => -0.12, 'settings' => ['communitynews' => 1]]);
        $gHigh = $this->createTestGroup(['lat' => 51.52, 'lng' => -0.10, 'settings' => ['communitynews' => 1]]);
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

        // A town appears within the cap of both groups: they merge into one
        // town area anchored on gLow, and gHigh's old area is reshaped away.
        $this->town('London', 51.5074, -0.1278);
        $svc->rebuildAreas();

        $this->assertSame(1, CommunityNewsArea::count());
        $merged = CommunityNewsArea::firstOrFail();
        $this->assertSame($gLow->id, (int) $merged->anchorgroupid);
        $this->assertSame('London', $merged->name);

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

    public function test_haversine_london_to_edinburgh_is_about_330_miles(): void
    {
        $d = $this->svc()->haversineMiles(51.5074, -0.1278, 55.9533, -3.1883);
        $this->assertGreaterThan(320, $d);
        $this->assertLessThan(360, $d);
    }
}
