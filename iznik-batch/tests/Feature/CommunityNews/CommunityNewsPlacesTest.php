<?php

namespace Tests\Feature\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Services\CommunityNews\CommunityNewsAreaService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An area's NAME is its authority's name, and often not the biggest town in
 * it. What it COVERS is the question the research prompt actually needs
 * answered, and `places` answers it without touching how areas are named.
 */
class CommunityNewsPlacesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    /** A rectangular authority, as a real polygon. */
    private function authority(int $id, string $name, float $minLat, float $minLng, float $maxLat, float $maxLng): void
    {
        $srid = (int) config('freegle.srid', 3857);
        $wkt = sprintf(
            'POLYGON((%1$f %2$f, %3$f %2$f, %3$f %4$f, %1$f %4$f, %1$f %2$f))',
            $minLng, $minLat, $maxLng, $maxLat
        );
        DB::insert(
            'INSERT INTO authorities (id, name, polygon) VALUES (?, ?, ST_GeomFromText(?, ?))',
            [$id, $name, $wkt, $srid]
        );
    }

    public function test_places_inside_the_area_come_back_biggest_first(): void
    {
        $this->place('Dunfermline', 56.0716, -3.4521, 54000);
        $this->place('Kirkcaldy', 56.1128, -3.1600, 49700);
        $this->place('Cupar', 56.3200, -3.0100, 9000);
        // Well outside the authority.
        $this->place('Carlisle', 54.8925, -2.9329, 75300);

        $this->authority(910101, 'Fife', 55.9, -3.6, 56.4, -2.9);
        $this->svc()->rebuildAreas();

        $area = CommunityNewsArea::where('authorityid', 910101)->first();
        $this->assertNotNull($area);

        $places = $this->svc()->placesCovered($area);

        $this->assertSame(['Fife', 'Dunfermline', 'Kirkcaldy', 'Cupar'], $places);
        $this->assertNotContains('Carlisle', $places);
    }

    public function test_the_area_name_survives_even_when_no_place_matches_it(): void
    {
        // The area is named after its authority, "Wrecsam" - there need not
        // be a `places` row of that exact name for it to stay in the list, or
        // the prompt would disown the name on the email.
        $this->place('Oswestry', 52.8620, -3.0550, 18743);

        $this->authority(910102, 'Wrecsam', 52.80, -3.10, 52.92, -3.00);
        $this->svc()->rebuildAreas();

        $area = CommunityNewsArea::where('authorityid', 910102)->first();
        $places = $this->svc()->placesCovered($area);

        $this->assertContains('Wrecsam', $places);
        $this->assertContains('Oswestry', $places);
    }

    public function test_the_list_is_capped(): void
    {
        foreach (range(1, 12) as $i) {
            $this->place("Place{$i}", 55.0 + $i * 0.005, -3.0, 1000 * (20 - $i));
        }
        $this->authority(910103, 'Capped', 54.9, -3.1, 55.1, -2.9);
        $this->svc()->rebuildAreas();

        $area = CommunityNewsArea::where('authorityid', 910103)->first();

        config(['freegle.communitynews.places_per_area' => 5]);
        $this->assertCount(5, $this->svc()->placesCovered($area));
    }

    public function test_an_area_with_no_places_inside_falls_back_to_its_name(): void
    {
        $this->authority(910104, 'Kirkwall', 58.85, -3.35, 58.95, -3.25);
        $this->svc()->rebuildAreas();

        $area = CommunityNewsArea::where('authorityid', 910104)->first();

        $this->assertSame(['Kirkwall'], $this->svc()->placesCovered($area));
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
