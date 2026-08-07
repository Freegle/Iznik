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
