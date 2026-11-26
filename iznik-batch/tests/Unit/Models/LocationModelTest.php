<?php

namespace Tests\Unit\Models;

use App\Models\Location;
use Tests\TestCase;

class LocationModelTest extends TestCase
{
    public function test_location_model_has_correct_table(): void
    {
        $location = new Location();
        $this->assertEquals('locations', $location->getTable());
    }

    public function test_location_model_has_no_timestamps(): void
    {
        $location = new Location();
        $this->assertFalse($location->timestamps);
    }

    public function test_location_model_casts(): void
    {
        $location = new Location();
        $casts = $location->getCasts();

        $this->assertArrayHasKey('lat', $casts);
        $this->assertArrayHasKey('lng', $casts);
        $this->assertArrayHasKey('osm_place', $casts);
        $this->assertArrayHasKey('osm_amenity', $casts);
        $this->assertArrayHasKey('osm_shop', $casts);
        $this->assertEquals('decimal:6', $casts['lat']);
        $this->assertEquals('decimal:6', $casts['lng']);
        $this->assertEquals('boolean', $casts['osm_place']);
        $this->assertEquals('boolean', $casts['osm_amenity']);
        $this->assertEquals('boolean', $casts['osm_shop']);
    }

    public function test_location_model_is_not_guarded_except_id(): void
    {
        $location = new Location();
        $this->assertEquals(['id'], $location->getGuarded());
    }
}
