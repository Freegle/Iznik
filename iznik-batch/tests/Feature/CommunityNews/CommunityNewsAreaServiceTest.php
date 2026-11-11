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

    /**
     * The areas containing any of THESE groups. Under opt-out the rebuild's
     * world can contain groups from concurrently-running tests (an unset flag
     * now means IN), so global counts flake - every assertion scopes to the
     * test's own groups instead. Tests also place their fixtures at remote
     * coordinates so a foreign group cannot geographically join their area.
     *
     * @param  int[]  $groupIds
     */
    private function areasContaining(array $groupIds)
    {
        return CommunityNewsArea::all()->filter(
            fn ($a) => array_intersect($a->groupids ?? [], $groupIds) !== []
        )->values();
    }

    private function town(string $name, float $lat, float $lng): int
    {
        return (int) DB::table('towns')->insertGetId(['name' => $name, 'lat' => $lat, 'lng' => $lng]);
    }

    private function place(string $name, float $lat, float $lng, int $population): int
    {
        return (int) DB::table('places')->insertGetId([
            'name' => $name,
            'lat' => $lat,
            'lng' => $lng,
            'population' => $population,
            'position' => DB::raw('ST_SRID(POINT(' . $lng . ', ' . $lat . '), 3857)'),
        ]);
    }

    private function nation(string $name, string $wkt, string $code = 'CUN'): int
    {
        return (int) DB::table('authorities')->insertGetId([
            'name' => $name,
            'area_code' => $code,
            'polygon' => DB::raw("ST_GeomFromText('$wkt', 3857)"),
        ]);
    }

    public function test_group_does_not_anchor_to_a_town_in_another_nation(): void
    {
        // Oswestry Freegle is in England but its nearest town, Wrexham, is in
        // Wales 12.7 miles away. Fabricated remote geography, same shape:
        // two adjacent nations split at lng -40. The group sits just EAST of
        // the border (lng -39.95, Testland East) while its nearest town is WEST.
        $this->nation('Testland East ' . uniqid(), 'POLYGON((-40 50, -38 50, -38 52, -40 52, -40 50))');
        $this->nation('Testland West ' . uniqid(), 'POLYGON((-42 50, -40 50, -40 52, -42 52, -42 50))');

        $this->town('Wrexhamlike', 51.0, -40.15);   // west of the border (Testland West), ~7 miles from the group
        $this->town('Shrewsburylike', 51.0, -39.55); // east of the border, ~15 miles away

        $oswestry = $this->createTestGroup(['lat' => 51.0, 'lng' => -39.95, 'settings' => ['communitynews' => 1]]);
        // A neighbour on the far side of the border: Wrexhamlike is rightly its town.
        $wrexham = $this->createTestGroup(['lat' => 51.0, 'lng' => -40.25, 'settings' => ['communitynews' => 1]]);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $o = $this->areasContaining([$oswestry->id])->first();
        $w = $this->areasContaining([$wrexham->id])->first();

        $this->assertSame('Shrewsburylike', $o->name);
        $this->assertSame('Wrexhamlike', $w->name);
    }

    public function test_detailed_polygons_override_rough_outline_near_border(): void
    {
        // The rough country outlines overreach: here the rough Wales outline
        // swallows an English group (Oswestry) and its English neighbour town.
        // The detailed Welsh Assembly polygon (WAE) stops at lng -60, and a
        // Westminster constituency (WMC) covers the English side, so the group
        // is England and must anchor to the English town, not Wrexham.
        DB::table('authorities')->whereIn('area_code', ['CUN', 'WAE', 'WMC'])
            ->where('name', 'like', 'Detail%')->delete();
        $this->nation('Wales', 'POLYGON((-62 50, -57 50, -57 52, -62 52, -62 50))');
        $this->nation('Detail Wales', 'POLYGON((-62 50, -60 50, -60 52, -62 52, -62 50))', 'WAE');
        $this->nation('Detail Constituency', 'POLYGON((-60 50, -57 50, -57 52, -60 52, -60 50))', 'WMC');

        $this->town('Wrexhamlike', 51.0, -60.15);    // Welsh side, ~7 miles from the group
        $this->town('Shrewsburylike', 51.0, -59.55); // English side, ~15 miles away

        $oswestry = $this->createTestGroup(['lat' => 51.0, 'lng' => -59.95, 'settings' => ['communitynews' => 1]]);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $this->assertSame('Shrewsburylike', $this->areasContaining([$oswestry->id])->first()->name);
    }

    // An area is named after the town closest to its communities. The curated towns list is
    // short, so for many communities "closest" was a town miles away: Oswestry Freegle was
    // filed under Wrecsam (12.7 miles, another nation) because the list has no Oswestry. The
    // places gazetteer has it. Remote coordinates throughout - see areasContaining().
    public function test_closest_place_anchors_when_no_curated_town_is_near(): void
    {
        $this->town('Wrecsamlike ' . uniqid(), 46.10, -50.0);          // ~13 miles north
        $this->place('Oswestrylike ' . uniqid(), 45.912, -50.0, 18743); // ~0.2 miles

        $g = $this->createTestGroup(['lat' => 45.91, 'lng' => -50.0, 'settings' => ['communitynews' => 1]]);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $area = CommunityNewsArea::where('anchorgroupid', $g->id)->first();
        $this->assertNotNull($area);
        $this->assertStringStartsWith('Oswestrylike', $area->name);
    }

    // A city's recorded centre sits among its neighbourhoods, each in the gazetteer. A curated
    // town close by is the deliberate choice and wins (Birmingham, not Aston).
    public function test_curated_town_within_reach_beats_a_closer_neighbourhood(): void
    {
        $this->town('Citylike ' . uniqid(), 46.513, -51.0);            // ~0.9 miles
        $this->place('Neighbourhoodlike ' . uniqid(), 46.508, -51.0, 60000); // ~0.6 miles

        $g = $this->createTestGroup(['lat' => 46.50, 'lng' => -51.0, 'settings' => ['communitynews' => 1]]);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $this->assertStringStartsWith('Citylike', CommunityNewsArea::where('anchorgroupid', $g->id)->first()->name);
    }

    // With no curated town near, a sizeable place beats a closer hamlet, so the area is named
    // after the town people know (Dundee, not a village across the water).
    public function test_sizeable_place_beats_a_closer_small_one(): void
    {
        $this->place('Hamletlike ' . uniqid(), 47.505, -52.0, 1500);   // ~0.3 miles
        $this->place('Townlike ' . uniqid(), 47.54, -52.0, 45000);     // ~2.8 miles

        $g = $this->createTestGroup(['lat' => 47.50, 'lng' => -52.0, 'settings' => ['communitynews' => 1]]);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $this->assertStringStartsWith('Townlike', CommunityNewsArea::where('anchorgroupid', $g->id)->first()->name);
    }

    // Communities whose closest place is the same one share an area.
    public function test_communities_sharing_a_closest_place_share_an_area(): void
    {
        $this->place('Sharedlike ' . uniqid(), 48.50, -53.0, 30000);

        $a = $this->createTestGroup(['lat' => 48.49, 'lng' => -53.0, 'settings' => ['communitynews' => 1]]);
        $b = $this->createTestGroup(['lat' => 48.51, 'lng' => -53.0, 'settings' => ['communitynews' => 1]]);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $areas = $this->areasContaining([$a->id, $b->id]);
        $this->assertSame(1, $areas->count());
        $this->assertSame(2, $areas->first()->groupcount);
        $this->assertStringStartsWith('Sharedlike', $areas->first()->name);
    }

    // A place that is also a curated town is one candidate, not two, so communities either
    // side of it cannot be split into twin areas of the same name.
    public function test_a_place_that_is_a_curated_town_is_not_a_second_anchor(): void
    {
        $name = 'Twinlike ' . uniqid();
        $this->town($name, 49.50, -54.0);
        $this->place($name, 49.501, -54.0, 30000);

        // 2.5 miles from the town, so it takes the town; 4 miles away, so it would take the
        // place - the twin - if the place were still listed.
        $a = $this->createTestGroup(['lat' => 49.464, 'lng' => -54.0, 'settings' => ['communitynews' => 1]]);
        $b = $this->createTestGroup(['lat' => 49.558, 'lng' => -54.0, 'settings' => ['communitynews' => 1]]);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $this->assertSame(1, $this->areasContaining([$a->id, $b->id])->count());
    }

    public function test_groups_assign_to_nearest_town(): void
    {
        // Areas are anchored on the towns table: each enabled group joins its
        // nearest town (within the cap), and the town's name/centre become the
        // area's — a real, searchable place, immune to union-find chaining.
        // Remote coordinates on purpose - see areasContaining().
        $this->town('Inverness', 57.4778, -4.2247);
        $this->town('Lerwick', 60.1547, -1.1494);

        $near1 = $this->createTestGroup(['lat' => 57.47, 'lng' => -4.23, 'settings' => ['communitynews' => 1]]);
        $near2 = $this->createTestGroup(['lat' => 57.49, 'lng' => -4.21, 'settings' => ['communitynews' => 1]]);
        $far   = $this->createTestGroup(['lat' => 60.15, 'lng' => -1.15, 'settings' => ['communitynews' => 1]]);
        // Explicitly opted out (under the opt-out default, an unset flag would
        // mean IN) -> must not appear in any area.
        $off   = $this->createTestGroup(['lat' => 57.48, 'lng' => -4.22, 'settings' => ['communitynews' => 0]]);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $this->assertSame(2, $this->areasContaining([$near1->id, $near2->id, $far->id])->count());

        $inverness = CommunityNewsArea::where('anchorgroupid', min($near1->id, $near2->id))->first();
        $this->assertNotNull($inverness);
        $this->assertSame('Inverness', $inverness->name);
        $this->assertSame(2, $inverness->groupcount);
        $this->assertEqualsCanonicalizing([$near1->id, $near2->id], $inverness->groupids);
        // Area centre is the TOWN centre (the searchable anchor), not a group average.
        $this->assertEqualsWithDelta(57.4778, (float) $inverness->lat, 0.001);
        $this->assertEqualsWithDelta(-4.2247, (float) $inverness->lng, 0.001);

        $lerwick = CommunityNewsArea::where('anchorgroupid', $far->id)->first();
        $this->assertNotNull($lerwick);
        $this->assertSame('Lerwick', $lerwick->name);
        $this->assertSame(1, $lerwick->groupcount);

        $allGroupIds = CommunityNewsArea::all()->flatMap(fn ($a) => $a->groupids)->all();
        $this->assertNotContains($off->id, $allGroupIds);
    }

    public function test_group_beyond_cap_stands_alone_named_from_group(): void
    {
        // Nearest town is far beyond the 20mi cap, so the group is its own
        // area, named from the group (stripped of "Freegle"), centred on it.
        // Remote coordinates on purpose - see areasContaining().
        $this->town('Kirkwall', 58.9809, -2.9605);

        $g = $this->createTestGroup(['lat' => 57.1497, 'lng' => -2.0943, 'settings' => ['communitynews' => 1]]);
        $g->update(['namefull' => 'Cambridge Freegle']);

        config(['freegle.communitynews.area_cluster_miles' => 20]);
        $this->svc()->rebuildAreas();

        $mine = $this->areasContaining([$g->id]);
        $this->assertSame(1, $mine->count());
        $area = $mine->first();
        $this->assertSame($g->id, $area->anchorgroupid);
        $this->assertStringNotContainsStringIgnoringCase('freegle', $area->name);
        $this->assertStringContainsStringIgnoringCase('Cambridge', $area->name);
        $this->assertEqualsWithDelta(57.1497, (float) $area->lat, 0.001);
    }

    public function test_no_towns_every_group_stands_alone(): void
    {
        // Dev/empty-towns fallback: no chaining substitute, one area per group.
        // Remote coordinates on purpose - see areasContaining().
        $a = $this->createTestGroup(['lat' => 58.21, 'lng' => -6.39, 'settings' => ['communitynews' => 1]]);
        $b = $this->createTestGroup(['lat' => 58.215, 'lng' => -6.38, 'settings' => ['communitynews' => 1]]);

        $this->svc()->rebuildAreas();

        $this->assertSame(2, $this->areasContaining([$a->id, $b->id])->count());
        $this->assertNotNull(CommunityNewsArea::where('anchorgroupid', $a->id)->first());
        $this->assertNotNull(CommunityNewsArea::where('anchorgroupid', $b->id)->first());
    }

    public function test_groups_are_in_by_default_and_can_opt_out(): void
    {
        // 2026-08-07: Community News flipped from opt-in to opt-OUT. A group
        // that has never touched the setting takes part; only an explicit
        // falsy flag opts out. Remote coordinates - see areasContaining().
        $this->town('Stornoway', 58.2094, -6.3869);
        $g = $this->createTestGroup(['lat' => 58.21, 'lng' => -6.39]);
        $svc = $this->svc();

        $svc->rebuildAreas();
        $this->assertSame(1, $this->areasContaining([$g->id])->count(), 'an unset flag means IN under opt-out');

        $g->update(['settings' => ['communitynews' => 0]]);
        $svc->rebuildAreas();
        $this->assertSame(0, $this->areasContaining([$g->id])->count(), 'an explicit 0 still opts out');
    }

    public function test_rebuild_is_idempotent_and_prunes_disabled(): void
    {
        // Remote coordinates on purpose - see areasContaining().
        $this->town('Thurso', 58.5936, -3.5221);
        $g = $this->createTestGroup(['lat' => 58.59, 'lng' => -3.52, 'settings' => ['communitynews' => 1]]);
        $svc = $this->svc();

        $svc->rebuildAreas();
        $mine = $this->areasContaining([$g->id]);
        $this->assertSame(1, $mine->count());
        $first = $mine->first();

        // Re-running upserts the same row (cadence timers survive), not a duplicate.
        $svc->rebuildAreas();
        $mine = $this->areasContaining([$g->id]);
        $this->assertSame(1, $mine->count());
        $this->assertSame($first->id, $mine->first()->id);

        // Turning the group off prunes its area (items cascade).
        $g->update(['settings' => ['communitynews' => 0]]);
        $svc->rebuildAreas();
        $this->assertSame(0, $this->areasContaining([$g->id])->count());
    }

    public function test_reshaped_area_rehomes_items_and_carries_stamps(): void
    {
        // No towns yet: two enabled groups stand alone as two areas. gLow gets
        // the lower id, so once a town captures both, the merged town area
        // anchors on it and gHigh's area becomes stale. Remote coordinates on
        // purpose - see areasContaining().
        $gLow = $this->createTestGroup(['lat' => 60.15, 'lng' => -1.15, 'settings' => ['communitynews' => 1]]);
        $gHigh = $this->createTestGroup(['lat' => 60.17, 'lng' => -1.13, 'settings' => ['communitynews' => 1]]);
        $svc = $this->svc();

        $svc->rebuildAreas();
        $this->assertSame(2, $this->areasContaining([$gLow->id, $gHigh->id])->count());

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
        $this->town('Lerwick', 60.1547, -1.1494);
        $svc->rebuildAreas();

        $mine = $this->areasContaining([$gLow->id, $gHigh->id]);
        $this->assertSame(1, $mine->count());
        $merged = $mine->first();
        $this->assertSame($gLow->id, (int) $merged->anchorgroupid);
        $this->assertSame('Lerwick', $merged->name);

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
