<?php

namespace Tests\Unit\Database;

use App\Database\Spatial;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SpatialTest extends TestCase
{
    public function test_point_renders_for_the_connections_dialect(): void
    {
        $expected = DB::connection()->getDriverName() === 'pgsql'
            ? 'ST_SetSRID(ST_MakePoint(?, lat), 4326)'
            : 'ST_SRID(POINT(?, lat), 4326)';

        $this->assertSame($expected, Spatial::point('?', 'lat', 4326));
    }
}
