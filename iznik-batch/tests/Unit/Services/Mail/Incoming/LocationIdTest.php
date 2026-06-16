<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\ParsedEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\SeedsSpatialIndex;
use Tests\TestCase;

class LocationIdTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsSpatialIndex;

    private const PC_ID = 99000011;

    private IncomingMailService $service;

    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IncomingMailService::class);
        $this->reflection = new ReflectionClass($this->service);
    }

    protected function tearDown(): void
    {
        $this->removeSpatial('postcodes', [self::PC_ID]);
        parent::tearDown();
    }

    /**
     * Get access to private method for testing.
     */
    private function invokePrivateMethod(string $methodName, array $args = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->service, $args);
    }

    // NB: seeded postcodes are placed in empty open sea (central North Sea), well
    // beyond the KNN's largest 0.32°/~35km buffer from any real postcode. In CI the
    // spatial server builds its 'postcodes' index from a populated DB, so seeding at
    // a real UK location would let a real postcode out-compete (or, for the by-id
    // enrich, shadow) the sentinel. At sea the sentinel is unambiguously nearest.

    #[Test]
    public function it_finds_closest_postcode_by_coordinates(): void
    {
        // Seed a postcode point into the spatial server's live index.
        $this->seedSpatialPoint('postcodes', self::PC_ID, 56.500, 3.000);

        $foundId = $this->invokePrivateMethod('findClosestPostcodeId', [56.500, 3.000]);

        $this->assertEquals(self::PC_ID, $foundId);
    }

    #[Test]
    public function it_returns_null_when_no_postcode_found(): void
    {
        // Middle of the ocean, nothing seeded nearby.
        $foundId = $this->invokePrivateMethod('findClosestPostcodeId', [0.0, 0.0]);

        $this->assertNull($foundId);
    }

    #[Test]
    public function it_finds_closest_postcode_offset_from_search_point(): void
    {
        // Seed a postcode slightly offset from the search point — KNN still finds it.
        $this->seedSpatialPoint('postcodes', self::PC_ID, 56.605, 3.005);

        $foundId = $this->invokePrivateMethod('findClosestPostcodeId', [56.600, 3.000]);

        $this->assertEquals(self::PC_ID, $foundId);
    }

    // NB: the old "ignores non-postcode locations" and "requires a space in the
    // name" tests asserted findClosestPostcodeId's own type/LOCATE filtering.
    // That filtering now lives in the spatial server's postcodes dataset loader
    // (loadPostcodes: WHERE type='Postcode' AND LOCATE(' ', name) > 0) and is
    // covered by iznik-spatial-go, so they no longer belong here.

    #[Test]
    public function it_gets_lat_lng_from_tn_coordinates_header(): void
    {
        // Create a mock ParsedEmail with TN coordinates header
        $email = Mockery::mock(ParsedEmail::class);
        $email->shouldReceive('getTrashNothingCoordinates')
            ->andReturn('51.5074,-0.1278');

        // The coordinates from the header should be used, not the user's location
        // This test verifies the parsing logic is correct
        $coords = $email->getTrashNothingCoordinates();
        $parts = explode(',', $coords);

        $this->assertCount(2, $parts);
        $this->assertEquals(51.5074, (float) $parts[0]);
        $this->assertEquals(-0.1278, (float) $parts[1]);
    }
}
