<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\Group;
use App\Services\ItemService;
use App\Services\LokiService;
use App\Services\Mail\Incoming\RoutingResult;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use App\Services\TrashNothing\Ingestion\TnUserProvisioner;
use App\Services\TrashNothing\Sync\PostSyncer;
use App\Services\TrashNothing\Sync\TrashNothingRateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
            ->setConstructorArgs([true, app(LokiService::class), app(ItemService::class), new TnUserProvisioner(true, false, 'test-key', app(LokiService::class))])
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
            'user_id'   => '4242',
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
        // TN's model types this as a string; the log always holds the number.
        $this->assertSame(4242, $entry['message']['tn_user_id']);
        // No group was resolved, so no group context — matching the shape of the
        // email path's own unknown-group drop.
        $this->assertArrayNotHasKey('group_id', $entry['message']);
    }

    public function test_a_post_that_fails_ingestion_still_logs_its_tn_user_id(): void
    {
        $this->createTestGroup(['lat' => 10.0, 'lng' => 10.0]);

        $syncer = $this->makeSyncer();
        $this->injectIngestionSpy($syncer)->method('ingest')->willThrowException(new \RuntimeException('ingest failed'));

        $this->callProcessPost($syncer, $this->makePost([
            'user_id'   => 4242,
            'latitude'  => 10.0,
            'longitude' => 10.0,
        ]));

        $entry = $this->onlyRoutedEntry();
        $this->assertSame(RoutingResult::FAILURE->value, $entry['labels']['subtype']);
        $this->assertSame(4242, $entry['message']['tn_user_id']);
    }

    public function test_a_post_tn_sent_with_no_user_id_logs_a_null_tn_user_id(): void
    {
        $syncer = $this->makeSyncer();
        $this->injectIngestionSpy($syncer)->expects($this->never())->method('ingest');

        $this->callProcessPost($syncer, $this->makePost());

        $entry = $this->onlyRoutedEntry();
        $this->assertArrayHasKey('tn_user_id', $entry['message']);
        $this->assertNull($entry['message']['tn_user_id']);
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

    /** Binds a recorder as 'sentry' and returns it. */
    private function recordSentry(): object
    {
        $sentry = new class
        {
            public array $calls = [];

            public function captureMessage($message, $severity = null): void
            {
                $this->calls[] = [(string) $message, (string) $severity];
            }
        };
        $this->app->instance('sentry', $sentry);

        return $sentry;
    }

    public function test_a_missing_consent_field_is_reported_once_a_run_on_an_array_post(): void
    {
        // A key without freegle_group_ids reads every post as unaddressed, which now makes
        // every poster an implicit member. That is a configuration fault, so the run says so:
        // once, however many posts it sees.
        $this->createTestGroup(['lat' => 10.0, 'lng' => 10.0]);
        $sentry = $this->recordSentry();

        $syncer = $this->makeSyncer();
        $spy    = $this->injectIngestionSpy($syncer);
        $spy->method('ingest')->willReturn('approved');

        $this->callProcessPost($syncer, $this->makePost(['latitude' => 10.0, 'longitude' => 10.0]));
        $this->callProcessPost($syncer, $this->makePost(['latitude' => 10.0, 'longitude' => 10.0]));

        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('freegle_group_ids', $sentry->calls[0][0]);
        $this->assertSame((string) \Sentry\Severity::error(), $sentry->calls[0][1]);
    }

    public function test_a_missing_consent_field_is_reported_once_a_run_on_a_deserialized_post(): void
    {
        // A fresh syncer and a fresh recorder: the array-post test above must not be able to
        // satisfy this one, so a deserialized Post that was never detected fails here.
        $this->createTestGroup(['lat' => 10.0, 'lng' => 10.0]);
        $sentry = $this->recordSentry();

        $syncer = $this->makeSyncer();
        $spy    = $this->injectIngestionSpy($syncer);
        $spy->method('ingest')->willReturn('approved');

        foreach ($this->deserializePosts([
            $this->makePost(['latitude' => 10.0, 'longitude' => 10.0]),
            $this->makePost(['latitude' => 10.0, 'longitude' => 10.0]),
        ]) as $post) {
            $this->callProcessPost($syncer, $post);
        }

        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('freegle_group_ids', $sentry->calls[0][0]);
        $this->assertSame((string) \Sentry\Severity::error(), $sentry->calls[0][1]);
    }

    public function test_an_empty_consent_list_is_the_posters_choice_and_not_reported(): void
    {
        $this->createTestGroup(['lat' => 10.0, 'lng' => 10.0]);
        $sentry = $this->recordSentry();

        $syncer = $this->makeSyncer();
        $spy    = $this->injectIngestionSpy($syncer);
        $spy->expects($this->once())
            ->method('ingest')
            ->with($this->anything(), $this->anything(), false)
            ->willReturn('approved');

        $this->callProcessPost($syncer, $this->deserializePosts([$this->makePost([
            'latitude' => 10.0, 'longitude' => 10.0, 'freegle_group_ids' => [],
        ])])[0]);

        $this->assertSame([], $sentry->calls);
    }

    // -------------------------------------------------------------------------
    // A poster TN could not be asked about stops the window. The cursor is stored from
    // what a run reached, so a post skipped and passed over is never fetched again.
    // -------------------------------------------------------------------------

    /** @var string[] */
    private array $fixtureDirs = [];

    /**
     * A syncer reading three posts from a fixture feed, with an ingestion double whose
     * answer for the Nth call is $outcomes[N] ('ok', 'unknown-user' or 'lookup-failed').
     *
     * @param  string[]  $outcomes
     * @return array{PostSyncer, object}
     */
    private function syncerOverThreePosts(array $outcomes): array
    {
        $this->createTestGroup(['lat' => 10.0, 'lng' => 10.0]);

        $dir = 'storage/framework/testing/tn-posts-' . uniqid('', true);
        mkdir(base_path($dir), 0755, true);
        $this->fixtureDirs[] = base_path($dir);
        $posts = [];
        foreach ([['p1', '2026-07-07T12:00:00Z'], ['p2', '2026-07-07T13:15:00Z'], ['p3', '2026-07-07T14:30:00Z']] as [$id, $date]) {
            $posts[] = $this->makePost(['post_id' => $id, 'date' => $date, 'latitude' => 10.0, 'longitude' => 10.0]);
        }
        file_put_contents(base_path($dir . '/posts_page_1.json'), json_encode(['num_pages' => 1, 'posts' => $posts]));

        $syncer = new PostSyncer(
            dryRun: true,
            localTesting: true,
            apiKey: 'test-key',
            apiBaseUrl: 'https://example.invalid',
            loki: app(LokiService::class),
            fixtureDir: $dir,
            rateLimiter: new TrashNothingRateLimiter(0),
        );

        $seen = new class
        {
            public array $ingested = [];
        };
        $reason = null;
        $spy = $this->getMockBuilder(GroupPostIngestionService::class)
            ->setConstructorArgs([true, app(LokiService::class), app(ItemService::class), new TnUserProvisioner(true, true, 'test-key', app(LokiService::class))])
            ->onlyMethods(['ingest', 'getLastRoutingContext'])
            ->getMock();
        $spy->method('ingest')->willReturnCallback(function ($post) use ($outcomes, $seen, &$reason) {
            $seen->ingested[] = $post['post_id'];
            $outcome = $outcomes[count($seen->ingested) - 1] ?? 'ok';
            $reason = match ($outcome) {
                'unknown-user'  => GroupPostIngestionService::REASON_UNKNOWN_USER,
                'lookup-failed' => GroupPostIngestionService::REASON_TN_USER_LOOKUP_FAILED,
                default         => null,
            };

            return $outcome === 'ok' ? 'approved' : 'skipped';
        });
        $spy->method('getLastRoutingContext')->willReturnCallback(function () use (&$reason) {
            return $reason === null ? [] : ['routing_reason' => $reason];
        });

        (new \ReflectionProperty(PostSyncer::class, 'ingestionService'))->setValue($syncer, $spy);

        return [$syncer, $seen];
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->tearDownLokiCapture();
        parent::tearDown();
    }

    public function test_a_poster_lookup_failure_stops_the_window_at_that_post(): void
    {
        [$syncer, $seen] = $this->syncerOverThreePosts(['ok', 'lookup-failed']);

        [$count, $maxDate] = $syncer->sync('2026-07-07T00:00:00Z', '2026-07-07T23:00:00Z');

        $this->assertSame(['p1', 'p2'], $seen->ingested, 'p3 is left for the run that can look the poster up');
        $this->assertSame(2, $count);
        // The feed's order is TN's, so anything in this window may be unseen: the cursor
        // may not pass the start of the window the failure was in.
        $this->assertSame('2026-07-07T00:00:00Z', $syncer->stalledAt());
        $this->assertSame('2026-07-07T00:00:00Z', $maxDate);
    }

    public function test_a_failure_in_a_later_window_lets_the_cursor_reach_that_window(): void
    {
        // Two day windows over a feed that serves the same page for each. The second
        // window's second post (the fifth call) cannot be looked up.
        [$syncer, $seen] = $this->syncerOverThreePosts(['ok', 'ok', 'ok', 'ok', 'lookup-failed']);

        [, $maxDate] = $syncer->sync('2026-07-07T00:00:00Z', '2026-07-09T00:00:00Z');

        $this->assertSame(['p1', 'p2', 'p3', 'p1', 'p2'], $seen->ingested);
        $this->assertSame('2026-07-08T00:00:00Z', $syncer->stalledAt());
        $this->assertSame('2026-07-07T14:30:00Z', $maxDate, 'what the first window finished');
    }

    public function test_a_definite_skip_does_not_stop_the_window(): void
    {
        // TN answered "no such user": retrying would get the same answer, so the post is
        // passed over and the cursor moves on.
        [$syncer, $seen] = $this->syncerOverThreePosts(['ok', 'unknown-user', 'ok']);

        [$count, $maxDate] = $syncer->sync('2026-07-07T00:00:00Z', '2026-07-07T23:00:00Z');

        $this->assertSame(['p1', 'p2', 'p3'], $seen->ingested);
        $this->assertSame(3, $count);
        $this->assertNull($syncer->stalledAt());
        $this->assertSame('2026-07-07T14:30:00Z', $maxDate);
    }

    public function test_a_later_run_is_not_held_by_an_earlier_stall(): void
    {
        [$syncer] = $this->syncerOverThreePosts(['lookup-failed']);
        $syncer->sync('2026-07-07T00:00:00Z', '2026-07-07T23:00:00Z');
        $this->assertNotNull($syncer->stalledAt());

        $syncer->sync('2026-07-07T00:00:00Z', '2026-07-07T23:00:00Z');

        $this->assertNull($syncer->stalledAt(), 'the second run got through every post');
    }

    public function test_a_backfilled_post_whose_poster_could_not_be_looked_up_says_so(): void
    {
        [$syncer, $seen] = $this->syncerOverThreePosts(['lookup-failed', 'ok']);
        $posts = json_decode(file_get_contents($this->fixtureDirs[0] . '/posts_page_1.json'), true)['posts'];

        $this->assertFalse($syncer->ingestFetchedPost($posts[0]), 'try again later');
        $this->assertTrue($syncer->ingestFetchedPost($posts[1]));
        $this->assertSame(['p1', 'p2'], $seen->ingested);
    }

    // -------------------------------------------------------------------------
    // The same stall with the real provisioner, so TN's answers decide what happens.
    // -------------------------------------------------------------------------

    /** @var array<int, int> The HTTP status TN answers a user lookup with, by TN user id. */
    private array $tnStatuses = [];

    private const WINDOW_START = '2026-07-07T00:00:00Z';

    private const WINDOW_END = '2026-07-07T23:00:00Z';

    /**
     * A fixture feed of three posts, p1 to p3, each by the TN user given, and the fake TN
     * that answers their lookups from $this->tnStatuses. ONE Http::fake reads the property,
     * because fakes merge and the first registered stub wins.
     *
     * @param  int[]  $tnUserIds
     */
    private function feedWithPosters(array $tnUserIds): string
    {
        $this->createTestGroup(['lat' => 10.0, 'lng' => 10.0]);

        $dir = 'storage/framework/testing/tn-posts-' . uniqid('', true);
        mkdir(base_path($dir), 0755, true);
        $this->fixtureDirs[] = base_path($dir);
        $dates = ['2026-07-07T12:00:00Z', '2026-07-07T13:15:00Z', '2026-07-07T14:30:00Z'];
        $posts = [];
        foreach (array_values($tnUserIds) as $i => $tnUserId) {
            $posts[] = $this->makePost([
                'post_id'           => 'p' . ($i + 1),
                'date'              => $dates[$i],
                'latitude'          => 10.0,
                'longitude'         => 10.0,
                'user_id'           => (string) $tnUserId,
                'freegle_group_ids' => [],
            ]);
        }
        file_put_contents(base_path($dir . '/posts_page_1.json'), json_encode(['num_pages' => 1, 'posts' => $posts]));

        Http::fake(function (Request $request) {
            if (!preg_match('#/users/(\d+)\?#', $request->url(), $m)) {
                return Http::response('not found', 404);
            }

            return Http::response('TN says no', $this->tnStatuses[(int) $m[1]] ?? 404, ['Content-Type' => 'text/html']);
        });

        return $dir;
    }

    /**
     * A syncer for one run over the feed: its own provisioner, as every tn:sync run has, so
     * only the cache carries anything from run to run.
     */
    private function syncerForRun(string $fixtureDir): PostSyncer
    {
        $loki        = app(LokiService::class);
        $rateLimiter = new TrashNothingRateLimiter(0);

        $syncer = new PostSyncer(
            dryRun: false,
            localTesting: true,
            apiKey: 'test-key',
            apiBaseUrl: 'https://example.invalid',
            loki: $loki,
            fixtureDir: $fixtureDir,
            rateLimiter: $rateLimiter,
        );

        // The feed is local but TN's user lookups are the real provisioner over faked HTTP.
        $ingestion = new GroupPostIngestionService(
            false,
            $loki,
            app(ItemService::class),
            new TnUserProvisioner(false, false, 'test-key', $loki, $rateLimiter),
        );
        (new \ReflectionProperty(PostSyncer::class, 'ingestionService'))->setValue($syncer, $ingestion);

        return $syncer;
    }

    private function tnUserId(): int
    {
        return random_int(900_000_000, 999_999_999);
    }

    /** @return array<int, array<string, mixed>> The routed Loki entries written for one post. */
    private function routedFor(string $postId): array
    {
        return array_values(array_filter(
            $this->routedEntries(),
            fn (array $entry) => ($entry['message']['tn_post_id'] ?? null) === $postId,
        ));
    }

    public function test_a_404_poster_ahead_of_a_failing_poster_does_not_hide_the_failure(): void
    {
        // p1's poster is answered with a 404 every run. That used to end the failing streak
        // before it fired, so a wedge behind it was never reported.
        $sentry = $this->recordSentry();
        [$gone, $down, $other] = [$this->tnUserId(), $this->tnUserId(), $this->tnUserId()];
        $this->tnStatuses = [$gone => 404, $down => 500, $other => 404];
        $dir = $this->feedWithPosters([$gone, $down, $other]);

        for ($run = 1; $run <= TnUserProvisioner::LOOKUP_WARN_AT; $run++) {
            $syncer = $this->syncerForRun($dir);
            [$count, $maxDate] = $syncer->sync(self::WINDOW_START, self::WINDOW_END);

            $this->assertSame(2, $count, "run {$run} stops at p2 and does not reach p3");
            $this->assertSame(self::WINDOW_START, $syncer->stalledAt());
            $this->assertSame(self::WINDOW_START, $maxDate);
            $this->assertCount($run < TnUserProvisioner::LOOKUP_WARN_AT ? 0 : 1, $sentry->calls, json_encode($sentry->calls));
        }

        $this->assertStringContainsString('p2', $sentry->calls[0][0]);
        $this->assertStringContainsString((string) $down, $sentry->calls[0][0]);
    }

    public function test_a_stalled_post_leaves_no_routed_entry_until_it_is_decided(): void
    {
        // One entry per post, however many runs it was held for.
        [$gone, $down, $other] = [$this->tnUserId(), $this->tnUserId(), $this->tnUserId()];
        $this->tnStatuses = [$gone => 404, $down => 503, $other => 404];
        $dir = $this->feedWithPosters([$gone, $down, $other]);

        for ($run = 1; $run < TnUserProvisioner::LOOKUP_GIVE_UP_AT; $run++) {
            $this->syncerForRun($dir)->sync(self::WINDOW_START, self::WINDOW_END);
            $this->assertSame([], $this->routedFor('p2'), "run {$run} held p2 without saying it was routed");
        }

        $syncer = $this->syncerForRun($dir);
        [$count, $maxDate] = $syncer->sync(self::WINDOW_START, self::WINDOW_END);

        $this->assertNull($syncer->stalledAt(), 'the post was given up, so the window got through');
        $this->assertSame(3, $count);
        $this->assertSame('2026-07-07T14:30:00Z', $maxDate);

        $entries = $this->routedFor('p2');
        $this->assertCount(1, $entries);
        $this->assertSame(RoutingResult::DROPPED->value, $entries[0]['labels']['subtype']);
        $this->assertSame('tn-user-lookup-gave-up', $entries[0]['message']['routing_reason']);
    }

    public function test_a_poster_failing_past_the_limit_is_skipped_and_reported_and_the_window_advances(): void
    {
        $sentry = $this->recordSentry();
        [$down, $gone, $other] = [$this->tnUserId(), $this->tnUserId(), $this->tnUserId()];
        $this->tnStatuses = [$down => 502, $gone => 404, $other => 404];
        $dir = $this->feedWithPosters([$down, $gone, $other]);

        // p1 is the failing one, so until it is given up nothing behind it is reached.
        for ($run = 1; $run < TnUserProvisioner::LOOKUP_GIVE_UP_AT; $run++) {
            $syncer = $this->syncerForRun($dir);
            [$count] = $syncer->sync(self::WINDOW_START, self::WINDOW_END);
            $this->assertSame(1, $count, "run {$run}");
            $this->assertNotNull($syncer->stalledAt());
        }
        $this->assertCount(1, $sentry->calls, 'the warning count was reached on the way');

        $syncer = $this->syncerForRun($dir);
        [$count, $maxDate] = $syncer->sync(self::WINDOW_START, self::WINDOW_END);

        $this->assertSame(3, $count, 'the posts behind the failing poster are reached');
        $this->assertNull($syncer->stalledAt());
        $this->assertSame('2026-07-07T14:30:00Z', $maxDate);
        $this->assertCount(2, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('skipped', $sentry->calls[1][0]);
        $this->assertStringContainsString('p1', $sentry->calls[1][0]);

        $this->assertCount(1, $this->routedFor('p1'));
        $this->assertCount(1, $this->routedFor('p2'));
        $this->assertCount(1, $this->routedFor('p3'));
    }

    public function test_a_server_error_below_the_limit_stalls_the_window(): void
    {
        $sentry = $this->recordSentry();
        [$ok, $down, $other] = [$this->tnUserId(), $this->tnUserId(), $this->tnUserId()];
        $this->tnStatuses = [$ok => 404, $down => 500, $other => 404];
        $dir = $this->feedWithPosters([$ok, $down, $other]);

        $syncer = $this->syncerForRun($dir);
        [$count, $maxDate] = $syncer->sync(self::WINDOW_START, self::WINDOW_END);

        $this->assertSame(2, $count, 'p3 is left for the run that can look p2 up');
        $this->assertSame(self::WINDOW_START, $syncer->stalledAt());
        $this->assertSame(self::WINDOW_START, $maxDate);
        $this->assertSame([], $sentry->calls, 'one failed run is a blip');
    }

    /**
     * A rejected key says nothing about the poster and fails every lookup until it is fixed,
     * so holding the window for it only holds up the posts behind. The post is skipped as a
     * miss, the posters Freegle already knows keep coming in, and Sentry is told the key is
     * rejected.
     */
    public function test_a_rejected_key_skips_the_post_without_holding_the_window(): void
    {
        $sentry = $this->recordSentry();
        [$gone, $rejected, $other] = [$this->tnUserId(), $this->tnUserId(), $this->tnUserId()];
        $this->tnStatuses = [$gone => 404, $rejected => 401, $other => 404];
        $dir = $this->feedWithPosters([$gone, $rejected, $other]);

        $syncer = $this->syncerForRun($dir);
        [$count, $maxDate] = $syncer->sync(self::WINDOW_START, self::WINDOW_END);

        $this->assertSame(3, $count, 'the posts behind p2 are reached in the same run');
        $this->assertNull($syncer->stalledAt());
        $this->assertSame('2026-07-07T14:30:00Z', $maxDate);

        $entry = $this->routedFor('p2');
        $this->assertCount(1, $entry);
        $this->assertSame(RoutingResult::DROPPED->value, $entry[0]['labels']['subtype']);
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_KEY_REJECTED, $entry[0]['message']['routing_reason']);

        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('rejected', $sentry->calls[0][0]);
    }

    /**
     * The coverage check's backfill asks about a missed post again on its next run, so a
     * rejected key there is "try later", with no routed entry until the post is decided.
     */
    public function test_a_backfilled_post_whose_key_is_rejected_is_tried_again_later(): void
    {
        $this->recordSentry();
        [$rejected, $gone] = [$this->tnUserId(), $this->tnUserId()];
        $this->tnStatuses = [$rejected => 403, $gone => 404];
        $dir = $this->feedWithPosters([$rejected, $gone]);
        $posts = json_decode(file_get_contents(base_path($dir . '/posts_page_1.json')), true)['posts'];

        $syncer = $this->syncerForRun($dir);

        $this->assertFalse($syncer->ingestFetchedPost($posts[0]), 'try again later');
        $this->assertSame([], $this->routedFor('p1'));
        $this->assertTrue($syncer->ingestFetchedPost($posts[1]), 'a definite answer is decided');
    }

    public function test_a_410_is_a_definite_skip_and_the_window_advances(): void
    {
        // TN has told us something about this poster that a retry will not change. The post
        // is passed over like an unknown user, not held for thirty runs.
        $sentry = $this->recordSentry();
        [$gone, $refused, $other] = [$this->tnUserId(), $this->tnUserId(), $this->tnUserId()];
        $this->tnStatuses = [$gone => 404, $refused => 410, $other => 410];
        $dir = $this->feedWithPosters([$gone, $refused, $other]);

        $syncer = $this->syncerForRun($dir);
        [$count, $maxDate] = $syncer->sync(self::WINDOW_START, self::WINDOW_END);

        $this->assertSame(3, $count);
        $this->assertNull($syncer->stalledAt());
        $this->assertSame('2026-07-07T14:30:00Z', $maxDate);

        $entry = $this->routedFor('p2');
        $this->assertCount(1, $entry);
        $this->assertSame(RoutingResult::DROPPED->value, $entry[0]['labels']['subtype']);
        $this->assertSame('tn-user-lookup-refused', $entry[0]['message']['routing_reason']);
        $this->assertSame('tn-user-lookup-refused', $this->routedFor('p3')[0]['message']['routing_reason']);

        $this->assertCount(1, $sentry->calls, 'one report for the status, not one per poster');
        $this->assertStringContainsString('410', $sentry->calls[0][0]);
    }

    /**
     * Guzzle quotes the full request URL, key included, in ApiException's
     * message. The 401 seen on 2026-09-25 wrote the key into the batch log.
     */
    public function test_redact_api_key_strips_the_key_from_a_quoted_request_url(): void
    {
        $message = '[401] Client error: `GET https://trashnothing.com/api/v1.4/posts/all'
            . '?types=offer%2Cwanted&per_page=50&page=1&api_key=SECRETVALUE123` resulted in a `401 Unauthorized` response';

        $out = PostSyncer::redactApiKey($message);

        $this->assertStringNotContainsString('SECRETVALUE123', $out);
        $this->assertStringContainsString('&api_key=<redacted>` resulted in', $out);
        $this->assertStringContainsString('per_page=50&page=1&', $out, 'other parameters are kept');
    }

    public function test_redact_api_key_leaves_text_without_a_key_alone(): void
    {
        $message = '[500] Server error: `GET https://trashnothing.com/api/v1.4/posts/all?page=1` resulted in a `500` response';

        $this->assertSame($message, PostSyncer::redactApiKey($message));
    }

    /**
     * The provisioner asks TN's users endpoint with the same developer key and the same
     * rate limiter as the posts sync, because TN limits per key, and writes only when the
     * syncer does.
     */
    public function test_the_user_provisioner_shares_the_syncers_key_limiter_and_dry_run(): void
    {
        $limiter = new TrashNothingRateLimiter(0);

        foreach ([true, false] as $dryRun) {
            $syncer = new PostSyncer(
                dryRun: $dryRun,
                localTesting: true,
                apiKey: 'developer-key',
                apiBaseUrl: 'https://example.invalid',
                loki: app(LokiService::class),
                rateLimiter: $limiter,
            );

            $ingestion = (new \ReflectionProperty(PostSyncer::class, 'ingestionService'))->getValue($syncer);
            $provisioner = (new \ReflectionProperty(GroupPostIngestionService::class, 'userProvisioner'))->getValue($ingestion);

            $read = fn (string $name) => (new \ReflectionProperty(TnUserProvisioner::class, $name))->getValue($provisioner);

            $this->assertSame('developer-key', $read('publicApiKey'));
            $this->assertSame($limiter, $read('rateLimiter'));
            $this->assertSame($dryRun, $read('dryRun'));
            $this->assertTrue($read('localTesting'));
        }
    }

    /**
     * The syncer must send the key it was constructed with, as the api_key
     * query parameter the public API documents. TNSyncCommand passes the
     * public developer key here, not the partner key.
     */
    public function test_api_client_is_built_with_the_key_it_was_given(): void
    {
        $syncer = new PostSyncer(
            dryRun: true,
            localTesting: false,
            apiKey: 'developer-key',
            apiBaseUrl: 'https://example.invalid',
            loki: app(LokiService::class),
        );

        $build = new \ReflectionMethod(PostSyncer::class, 'buildApiClient');
        $client = $build->invoke($syncer);

        $this->assertSame('developer-key', $client->getConfig()->getApiKey('api_key'));
        $this->assertNull($client->getConfig()->getApiKeyPrefix('api_key'), 'sent bare, no Bearer prefix');
    }
}
