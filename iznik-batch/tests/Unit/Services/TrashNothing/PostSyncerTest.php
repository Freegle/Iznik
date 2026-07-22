<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\Group;
use App\Services\ItemService;
use App\Services\LokiService;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use App\Services\TrashNothing\Sync\PostSyncer;
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

    private function callProcessPost(PostSyncer $syncer, array $post): void
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
        $near = $this->createTestGroup(['lat' => 51.5074, 'lng' => -0.1278]); // London
        $far  = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]); // Edinburgh

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
            'latitude'  => 51.5074,
            'longitude' => -0.1278,
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

        $this->callProcessPost($syncer, $this->makePost([
            'group_id'  => (string) $group->id,
            'latitude'  => null,
            'longitude' => null,
        ]));
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

        $this->callProcessPost($syncer, $this->makePost([
            'group_id'  => (string) $group->id,
            'latitude'  => 35.0,
            'longitude' => -40.0,
        ]));
    }
}
