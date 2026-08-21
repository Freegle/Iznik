<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\Group;
use App\Services\ItemService;
use App\Services\LokiService;
use App\Services\Mail\Incoming\RoutingResult;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use App\Services\TrashNothing\Sync\PostSyncer;
use OpenAPI\Client\Model\GetAllPosts200Response;
use OpenAPI\Client\ObjectSerializer;
use Tests\TestCase;

/**
 * Tests PostSyncer's group resolution: it must pick the Freegle group whose
 * area contains the post's own lat/lng, not the group_id TN happens to report
 * (which is just wherever the member posted from and drifts from Freegle's
 * group boundaries). group_id is never used to resolve a group — a post with
 * no coordinates, or coordinates outside every group's bounds, is skipped
 * rather than falling back to it.
 */
class PostSyncerTest extends TestCase
{
    use CapturesRoutedLokiEntries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableLokiCapture();
    }

    protected function tearDown(): void
    {
        $this->tearDownLokiCapture();
        parent::tearDown();
    }

    private function makeSyncer(): PostSyncer
    {
        return new PostSyncer(
            dryRun: true,
            localTesting: false,
            apiKey: 'test-key',
            apiBaseUrl: 'https://example.invalid',
            loki: app(LokiService::class),
        );
    }

    /**
     * Replace the syncer's real ingestion service with a mock so we can assert
     * which Group it was called with, without needing users/memberships set up.
     */
    private function injectIngestionSpy(PostSyncer $syncer): GroupPostIngestionService
    {
        $spy = $this->getMockBuilder(GroupPostIngestionService::class)
            ->setConstructorArgs([true, app(LokiService::class), app(ItemService::class)])
            ->onlyMethods(['ingest'])
            ->getMock();

        $property = new \ReflectionProperty(PostSyncer::class, 'ingestionService');
        $property->setAccessible(true);
        $property->setValue($syncer, $spy);

        return $spy;
    }

    private function callProcessPost(PostSyncer $syncer, mixed $post): void
    {
        $method = new \ReflectionMethod(PostSyncer::class, 'processPost');
        $method->invoke($syncer, $post, null);
    }



    private function makePost(array $overrides = []): array
    {
        return array_merge([
            'post_id'   => 'tn-loc-test-' . uniqid('', true),
            'group_id'  => '',
            'user_id'   => null,
            'title'     => 'Old wooden bookshelf',
            'content'   => 'Good condition, free to collect.',
            'date'      => '2026-07-07T12:00:00Z',
            'type'      => 'offer',
            'latitude'  => null,
            'longitude' => null,
        ], $overrides);
    }

    public function test_resolves_group_by_location_instead_of_api_group_id(): void
    {
        // Deliberately placed in the middle of the ocean, far from the UK (and from the
        // default London coordinates other tests' createTestGroup() calls use), so this
        // test's own groups are the only candidates within range — no real seeded group
        // or another test's group can tie with or beat them on distance.
        $nearLat = 10.0;
        $nearLng = 10.0;

        $near = $this->createTestGroup(['lat' => $nearLat, 'lng' => $nearLng]);
        $far  = $this->createTestGroup(['lat' => -10.0, 'lng' => 100.0]);

        $syncer = $this->makeSyncer();
        $spy    = $this->injectIngestionSpy($syncer);

        $spy->expects($this->once())
            ->method('ingest')
            ->with($this->anything(), $this->callback(fn (Group $group) => $group->id === $near->id))
            ->willReturn('approved');

        // API says the post belongs to the far group, but its coordinates sit
        // right on the near group's centroid.
        $this->callProcessPost($syncer, $this->makePost([
            'group_id'  => (string) $far->id,
            'latitude'  => $nearLat,
            'longitude' => $nearLng,
        ]));
    }

    public function test_skips_post_with_no_coordinates_even_with_valid_group_id(): void
    {
        // A post with a perfectly valid group_id must still be skipped if we have no
        // coordinates to resolve a group from — group_id is never used as a resolver.
        $group = $this->createTestGroup();

        $syncer = $this->makeSyncer();
        $spy    = $this->injectIngestionSpy($syncer);

        $spy->expects($this->never())->method('ingest');

        $postId = 'tn-nocoords-' . uniqid('', true);
        $this->callProcessPost($syncer, $this->makePost([
            'post_id'   => $postId,
            'group_id'  => (string) $group->id,
            'latitude'  => null,
            'longitude' => null,
        ]));

        // Skipping before ingestion used to leave no trace outside the trace
        // logs, which are not shipped to Loki — so a post dropped here was
        // invisible in the comparison against the email path.
        $entry = $this->onlyRoutedEntry();
        $this->assertSame(RoutingResult::DROPPED->value, $entry['labels']['subtype']);
        $this->assertSame('no-coordinates', $entry['message']['routing_reason']);
        $this->assertSame($postId, $entry['message']['tn_post_id']);
        // No group was resolved, so no group context — matching the shape of the
        // email path's own unknown-group drop.
        $this->assertArrayNotHasKey('group_id', $entry['message']);
    }

    public function test_skips_post_whose_coordinates_are_outside_any_group_bounds(): void
    {
        // A group exists, but nowhere near these coordinates (middle of the Atlantic),
        // so Location::groupsNear() finds nothing and the post must be skipped —
        // group_id must not be used as a fallback.
        $group = $this->createTestGroup(['lat' => 51.5074, 'lng' => -0.1278]); // London

        $syncer = $this->makeSyncer();
        $spy    = $this->injectIngestionSpy($syncer);

        $spy->expects($this->never())->method('ingest');

        $postId = 'tn-oob-' . uniqid('', true);
        $this->callProcessPost($syncer, $this->makePost([
            'post_id'   => $postId,
            'group_id'  => (string) $group->id,
            'latitude'  => 35.0,
            'longitude' => -40.0,
        ]));

        // The single most important entry in this stream: a post the API path
        // could not place in any group is exactly the coverage regression
        // tn:parity-check's Layer 1 exists to catch, and it was previously
        // invisible in Loki.
        $entry = $this->onlyRoutedEntry();
        $this->assertSame(RoutingResult::DROPPED->value, $entry['labels']['subtype']);
        $this->assertSame('not-in-any-group-bounds', $entry['message']['routing_reason']);
        $this->assertSame($postId, $entry['message']['tn_post_id']);
    }

    public function test_mod_messaging_disallowed_when_freegle_group_ids_absent(): void
    {
        // Non-FD API key / no field at all: no consent given, so every TN post
        // defaults to mod messaging disallowed.
        $this->createTestGroup(['lat' => 10.0, 'lng' => 10.0]);

        $syncer = $this->makeSyncer();
        $spy    = $this->injectIngestionSpy($syncer);

        $spy->expects($this->once())
            ->method('ingest')
            ->with($this->anything(), $this->anything(), false)
            ->willReturn('approved');

        $this->callProcessPost($syncer, $this->makePost([
            'latitude'  => 10.0,
            'longitude' => 10.0,
        ]));
    }

    public function test_mod_messaging_allowed_true_when_resolved_group_in_freegle_group_ids(): void
    {
        $group = $this->createTestGroup(['lat' => 10.0, 'lng' => 10.0]);

        $syncer = $this->makeSyncer();
        $spy    = $this->injectIngestionSpy($syncer);

        $spy->expects($this->once())
            ->method('ingest')
            ->with($this->anything(), $this->anything(), true)
            ->willReturn('approved');

        $this->callProcessPost($syncer, $this->makePost([
            'latitude'          => 10.0,
            'longitude'         => 10.0,
            'freegle_group_ids' => [$group->id],
        ]));
    }

    public function test_mod_messaging_allowed_false_when_resolved_group_not_in_freegle_group_ids(): void
    {
        // TN explicitly told us which groups the poster consented to — resolved
        // group isn't among them, so messaging is disallowed.
        $this->createTestGroup(['lat' => 10.0, 'lng' => 10.0]);
        $other = $this->createTestGroup(['lat' => -10.0, 'lng' => 100.0]);

        $syncer = $this->makeSyncer();
        $spy    = $this->injectIngestionSpy($syncer);

        $spy->expects($this->once())
            ->method('ingest')
            ->with($this->anything(), $this->anything(), false)
            ->willReturn('approved');

        $this->callProcessPost($syncer, $this->makePost([
            'latitude'          => 10.0,
            'longitude'         => 10.0,
            'freegle_group_ids' => [$other->id],
        ]));
    }

    /**
     * Turn a raw API payload into the Post objects PostSyncer actually receives in
     * production, using the same deserializer PostsApi uses.
     *
     * @return \OpenAPI\Client\Model\Post[]
     */
    private function deserializePosts(array $posts): array
    {
        $response = ObjectSerializer::deserialize(
            json_encode(['posts' => $posts]),
            GetAllPosts200Response::class,
        );

        return $response->getPosts();
    }

    public function test_mod_messaging_consent_survives_api_deserialization(): void
    {
        // The tests above hand processPost() plain arrays, which is only ever the
        // --local-testing fixture shape. On the live path PostsApi returns Post objects
        // built by ObjectSerializer::deserialize(), which constructs an empty model and
        // copies across only the properties named in openAPITypes()/attributeMap()/
        // setters(). freegle_group_ids is not in TN's published spec, so if it is missing
        // from those maps — or is deleted by a regeneration of the client — it is dropped
        // in silence and every TN post is stamped as no-consent in production while every
        // array-based test above still passes. That is the regression this test exists for.
        $group = $this->createTestGroup(['lat' => 10.0, 'lng' => 10.0]);

        $posts = $this->deserializePosts([$this->makePost([
            'latitude'          => 10.0,
            'longitude'         => 10.0,
            'freegle_group_ids' => [$group->id],
        ])]);

        $this->assertCount(1, $posts);
        $this->assertSame([(int) $group->id], $posts[0]->getFreegleGroupIds());

        $syncer = $this->makeSyncer();
        $spy    = $this->injectIngestionSpy($syncer);

        $spy->expects($this->once())
            ->method('ingest')
            ->with($this->anything(), $this->anything(), true)
            ->willReturn('approved');

        $this->callProcessPost($syncer, $posts[0]);
    }

    public function test_mod_messaging_disallowed_after_api_deserialization_when_field_absent(): void
    {
        // Same live path, no consent field: the property must come back null rather than
        // erroring, and the post stays disallowed.
        $this->createTestGroup(['lat' => 10.0, 'lng' => 10.0]);

        $posts = $this->deserializePosts([$this->makePost([
            'latitude'  => 10.0,
            'longitude' => 10.0,
        ])]);

        $this->assertNull($posts[0]->getFreegleGroupIds());

        $syncer = $this->makeSyncer();
        $spy    = $this->injectIngestionSpy($syncer);

        $spy->expects($this->once())
            ->method('ingest')
            ->with($this->anything(), $this->anything(), false)
            ->willReturn('approved');

        $this->callProcessPost($syncer, $posts[0]);
    }
}
