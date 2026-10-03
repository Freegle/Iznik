<?php

namespace Tests\Feature\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Services\CommunityNews\CommunityNewsAreaService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityNewsAreaServiceTest extends TestCase
{
    private function svc(): CommunityNewsAreaService
    {
        return app(CommunityNewsAreaService::class);
    }

    /**
     * A square authority, one degree on each side, centred on (lat, lng).
     */
    private function authority(int $id, string $name, float $lat, float $lng): void
    {
        $half = 0.5;
        $srid = (int) config('freegle.srid', 3857);
        $wkt = sprintf(
            'POLYGON((%1$f %2$f, %3$f %2$f, %3$f %4$f, %1$f %4$f, %1$f %2$f))',
            $lng - $half, $lat - $half, $lng + $half, $lat + $half
        );

        DB::insert(
            'INSERT INTO authorities (id, name, polygon) VALUES (?, ?, ST_GeomFromText(?, ?))',
            [$id, $name, $wkt, $srid]
        );
    }

    public function test_rebuild_creates_one_area_per_authority_centred_on_it(): void
    {
        $this->authority(910001, 'Test County', 52.5, -1.5);

        $areas = $this->svc()->rebuildAreas();

        $area = CommunityNewsArea::where('authorityid', 910001)->first();
        $this->assertNotNull($area);
        $this->assertSame('Test County', $area->name);
        $this->assertEqualsWithDelta(52.5, (float) $area->lat, 0.001);
        $this->assertEqualsWithDelta(-1.5, (float) $area->lng, 0.001);
        $this->assertTrue($areas->contains(fn ($a) => $a->id === $area->id));
    }

    public function test_rebuild_is_idempotent_and_keeps_cadence_stamps(): void
    {
        $this->authority(910002, 'Repeat County', 51.0, 0.0);
        $svc = $this->svc();

        $svc->rebuildAreas();
        $first = CommunityNewsArea::where('authorityid', 910002)->firstOrFail();
        $first->update(['lastemailed' => now()->subDay()]);

        // Re-running upserts the same row rather than duplicating it, and
        // does not disturb its cadence timers.
        $svc->rebuildAreas();

        $this->assertSame(1, CommunityNewsArea::where('authorityid', 910002)->count());
        $again = CommunityNewsArea::where('authorityid', 910002)->firstOrFail();
        $this->assertSame($first->id, $again->id);
        $this->assertNotNull($again->lastemailed);
    }

    public function test_removed_authority_prunes_its_area_and_items(): void
    {
        $this->authority(910003, 'Doomed County', 55.0, -2.0);
        $svc = $this->svc();
        $svc->rebuildAreas();

        $area = CommunityNewsArea::where('authorityid', 910003)->firstOrFail();
        $item = CommunityNewsItem::create([
            'areaid' => $area->id,
            'title' => 'History',
            'snippet' => 'Keep me.',
            'url' => 'https://example.org/h',
            'researched_at' => now()->subHours(2),
            'newsfeedid' => 987654321,
            'posted_at' => now()->subHour(),
        ]);

        // The authority is removed (a boundary reorganisation) - the area and
        // its item history go with it.
        DB::table('authorities')->where('id', 910003)->delete();
        $svc->rebuildAreas();

        $this->assertNull(CommunityNewsArea::find($area->id));
        $this->assertNull(CommunityNewsItem::find($item->id));
    }

    public function test_haversine_london_to_edinburgh_is_about_330_miles(): void
    {
        $d = $this->svc()->haversineMiles(51.5074, -0.1278, 55.9533, -3.1883);
        $this->assertGreaterThan(320, $d);
        $this->assertLessThan(360, $d);
    }
}
