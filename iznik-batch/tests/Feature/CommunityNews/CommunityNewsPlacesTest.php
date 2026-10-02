<?php

namespace Tests\Feature\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Services\CommunityNews\CommunityNewsAreaService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An area's NAME is one town and often not even one inside it. What it COVERS
 * is the question the research prompt actually needs answered, and `places`
 * answers it without touching how areas are anchored.
 */
class CommunityNewsPlacesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Same reason as CommunityNewsAreaServiceTest: opt-out means the seeded
        // groups would otherwise flood every count.
        DB::statement("UPDATE `groups` SET settings = JSON_SET(COALESCE(settings, '{}'), '$.communitynews', 0)");
        DB::table('places')->delete();
    }

    private function svc(): CommunityNewsAreaService
    {
        return app(CommunityNewsAreaService::class);
    }

    private function place(string $name, float $lat, float $lng, int $pop): void
    {
        $srid = (int) config('freegle.srid', 3857);
        DB::statement(
            'INSERT INTO places (name, lat, lng, population, position) VALUES (?, ?, ?, ?, ST_SRID(POINT(?, ?), ' . $srid . '))',
            [$name, $lat, $lng, $pop, $lng, $lat]
        );
    }

    /** A square catchment around a point, as a real polygon. */
    private function boundary($group, float $lat, float $lng, float $half): void
    {
        $srid = (int) config('freegle.srid', 3857);
        $wkt = sprintf(
            'POLYGON((%1$f %2$f, %3$f %2$f, %3$f %4$f, %1$f %4$f, %1$f %2$f))',
            $lng - $half, $lat - $half, $lng + $half, $lat + $half
        );
        DB::statement('UPDATE `groups` SET polyindex = ST_GeomFromText(?, ?) WHERE id = ?', [$wkt, $srid, $group->id]);
    }

    public function test_places_inside_the_area_come_back_biggest_first(): void
    {
        $this->place('Dunfermline', 56.0716, -3.4521, 54000);
        $this->place('Kirkcaldy', 56.1128, -3.1600, 49700);
        $this->place('Cupar', 56.3200, -3.0100, 9000);
        // Well outside the catchment.
        $this->place('Carlisle', 54.8925, -2.9329, 75300);

        $g = $this->createTestGroup(['lat' => 56.15, 'lng' => -3.2, 'settings' => ['communitynews' => 1]]);
        $this->boundary($g, 56.15, -3.2, 0.35);

        DB::table('towns')->insert(['name' => 'Glenrothes', 'lat' => 56.1980, 'lng' => -3.1780]);
        $this->svc()->rebuildAreas();

        $area = CommunityNewsArea::where('anchorgroupid', $g->id)->first();
        $this->assertNotNull($area);

        $places = $this->svc()->placesCovered($area);

        $this->assertSame(['Dunfermline', 'Kirkcaldy', 'Cupar'], array_values(array_diff($places, ['Glenrothes'])));
        $this->assertNotContains('Carlisle', $places);
    }

    public function test_the_area_name_survives_even_when_it_sits_outside(): void
    {
        // Oswestry Freegle's area is named "Wrecsam", 12.7 miles away and over
        // the border. Dropping the name from the prompt would have the email
        // disown its own subject line.
        $this->place('Oswestry', 52.8620, -3.0550, 18743);

        $g = $this->createTestGroup(['lat' => 52.866, 'lng' => -3.021, 'settings' => ['communitynews' => 1]]);
        $this->boundary($g, 52.866, -3.021, 0.08);

        DB::table('towns')->insert(['name' => 'Wrecsam', 'lat' => 53.05, 'lng' => -3.00]);
        $this->svc()->rebuildAreas();

        $area = CommunityNewsArea::where('anchorgroupid', $g->id)->first();
        $places = $this->svc()->placesCovered($area);

        $this->assertContains('Wrecsam', $places);
        $this->assertContains('Oswestry', $places);
    }

    public function test_the_list_is_capped(): void
    {
        foreach (range(1, 12) as $i) {
            $this->place("Place{$i}", 55.0 + $i * 0.005, -3.0, 1000 * (20 - $i));
        }
        $g = $this->createTestGroup(['lat' => 55.03, 'lng' => -3.0, 'settings' => ['communitynews' => 1]]);
        $this->boundary($g, 55.03, -3.0, 0.4);
        DB::table('towns')->insert(['name' => 'Anchor', 'lat' => 55.03, 'lng' => -3.0]);
        $this->svc()->rebuildAreas();

        $area = CommunityNewsArea::where('anchorgroupid', $g->id)->first();

        config(['freegle.communitynews.places_per_area' => 5]);
        $this->assertCount(5, $this->svc()->placesCovered($area));
    }

    public function test_an_area_with_no_places_inside_falls_back_to_its_name(): void
    {
        $g = $this->createTestGroup(['lat' => 58.9, 'lng' => -3.3, 'settings' => ['communitynews' => 1]]);
        $this->boundary($g, 58.9, -3.3, 0.05);
        DB::table('towns')->insert(['name' => 'Kirkwall', 'lat' => 58.9, 'lng' => -3.3]);
        $this->svc()->rebuildAreas();

        $area = CommunityNewsArea::where('anchorgroupid', $g->id)->first();

        $this->assertSame(['Kirkwall'], $this->svc()->placesCovered($area));
    }

    public function test_loading_places_does_not_disturb_existing_areas(): void
    {
        // The whole reason places is its own table. Areas are anchored on
        // `towns`; a newly created area has no lastposted, and the hourly
        // ChitChat job posts to any area that lacks one - so re-bucketing the
        // areas would have posted to every new area at once and dropped the
        // items the old ones held.
        DB::table('towns')->insert(['name' => 'Thurso', 'lat' => 58.5936, 'lng' => -3.5220]);

        $a = $this->createTestGroup(['lat' => 58.59, 'lng' => -3.52, 'settings' => ['communitynews' => 1]]);
        $b = $this->createTestGroup(['lat' => 58.60, 'lng' => -3.51, 'settings' => ['communitynews' => 1]]);
        $this->boundary($a, 58.59, -3.52, 0.2);
        $this->boundary($b, 58.60, -3.51, 0.2);

        $this->svc()->rebuildAreas();
        $before = CommunityNewsArea::whereIn('anchorgroupid', [$a->id, $b->id])->get()
            ->map(fn ($x) => [$x->id, $x->anchorgroupid, $x->name, $x->groupcount])->toArray();
        $this->assertCount(1, $before, 'both groups should share one area');

        // Now fill the gazetteer with places inside both catchments.
        $this->place('Thurso', 58.5936, -3.5220, 7900);
        $this->place('Castletown', 58.5860, -3.3800, 1000);
        $this->place('Halkirk', 58.5100, -3.4900, 1000);

        $this->svc()->rebuildAreas();
        $after = CommunityNewsArea::whereIn('anchorgroupid', [$a->id, $b->id])->get()
            ->map(fn ($x) => [$x->id, $x->anchorgroupid, $x->name, $x->groupcount])->toArray();

        $this->assertSame($before, $after, 'places must not re-anchor, rename or split an area');
    }

    public function test_the_loader_is_idempotent(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'places') . '.csv';
        file_put_contents($csv, "name,lat,lng,population\nBo’ness,56.01667,-3.61667,14840\nTobermory,56.62,-6.07,1000\n");

        $this->artisan('community-news:load-places', ['--file' => $csv])->assertSuccessful();
        $this->assertSame(2, DB::table('places')->count());

        // Same file again: updates, never duplicates. And the curly apostrophe
        // must survive the round trip - the curated towns table still carries a
        // mojibake "Pont-y-pÅµl" from an encoding slip.
        $this->artisan('community-news:load-places', ['--file' => $csv])->assertSuccessful();
        $this->assertSame(2, DB::table('places')->count());
        $this->assertSame('Bo’ness', DB::table('places')->orderByDesc('population')->value('name'));

        @unlink($csv);
    }
}
