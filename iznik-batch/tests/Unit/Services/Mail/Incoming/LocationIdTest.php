<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\Mail\Incoming\ParsedEmail;
use App\Services\Mail\Incoming\RoutingResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

    /**
     * Seeded into the spatial index but deliberately NOT inserted into `locations`, which is
     * exactly the state a purged or renumbered location leaves behind.
     */
    private const STALE_PC_ID = 99000012;

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
        $this->removeSpatial('postcodes', [self::PC_ID, self::STALE_PC_ID]);
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

    /**
     * A stale spatial-index entry must cost the post its location, not the post.
     *
     * The spatial server keeps its own R-tree, built from MySQL on its own schedule, so it
     * can hand back the id of a `locations` row that no longer exists. users.lastlocation is
     * a foreign key, so writing that id throws - and it is written inside
     * createGroupPostMessage, so the throw takes the whole post down with it rather than
     * just its location. That is how it showed up: a TN post email routed PENDING but
     * created no messages row at all.
     *
     * GroupPostIngestionService guards the API path the same way; both are covered because
     * either one silently loses posts when the index drifts.
     */
    #[Test]
    public function it_posts_without_a_location_when_the_spatial_index_is_stale(): void
    {
        // Sea point again (see the note above), with an id that is NOT in `locations`.
        $this->assertNull(
            DB::table('locations')->where('id', self::STALE_PC_ID)->first(),
            'Fixture id must not exist as a real location or this proves nothing'
        );
        $this->seedSpatialPoint('postcodes', self::STALE_PC_ID, 56.700, 3.100);

        $group = $this->createTestGroup(['lat' => 56.700, 'lng' => 3.100]);
        $user = $this->createTestUser(['lastlocation' => null]);
        $userEmail = $this->createTestUserEmail($user, ['preferred' => 1]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'MODERATED']);

        $postId = 'tn-stale-loc-'.uniqid();
        $envelopeTo = $group->nameshort.'@'.config('freegle.mail.group_domain', 'groups.ilovefreegle.org');
        $raw = $this->buildTnPostEmail($userEmail->email, $envelopeTo, 'OFFER: Old wooden bookshelf', $postId);

        $parsed = app(MailParserService::class)->parse($raw, $userEmail->email, $envelopeTo);
        $result = $this->service->route($parsed);

        $this->assertSame(RoutingResult::PENDING, $result);

        $message = DB::table('messages')->where('fromuser', $user->id)->first();
        $this->assertNotNull($message, 'The post was lost rather than posted without a location');
        $this->assertNull($message->locationid);
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('lastlocation'));
    }

    /**
     * A TN post email, headers as EmailReplaySyncer::buildRawEmail() writes them.
     *
     * X-Trash-Nothing-Secret must be PRESENT (even empty) or shouldSkipSpamCheck()'s
     * unconfigured-secret fallback never fires and the post routes as spam instead.
     */
    private function buildTnPostEmail(string $from, string $to, string $subject, string $postId): string
    {
        $headers = [
            'From' => $from,
            'To' => $to,
            'Subject' => $subject,
            'Date' => now()->format('D, d M Y H:i:s O'),
            'Message-ID' => '<'.$postId.'@tn.trashnothing.com>',
            'X-Trash-Nothing-Secret' => (string) config('freegle.mail.trashnothing_secret', ''),
            'X-Trash-Nothing-Post-Id' => $postId,
            'X-Trash-Nothing-Post-Coordinates' => '56.700,3.100',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
        ];

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return implode("\r\n", $lines)."\r\n\r\nGood condition, free to collect.";
    }
}
