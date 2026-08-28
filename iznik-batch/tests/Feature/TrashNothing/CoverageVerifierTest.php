<?php

namespace Tests\Feature\TrashNothing;

use App\Services\TrashNothing\Sync\PostSyncer;
use App\Services\TrashNothing\Verify\CoverageVerifier;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The classification rules that stop tn:verify-email-coverage from crying wolf.
 *
 * Every bucket other than GENUINE exists because a post can be legitimately
 * absent from `messages` — crossposts above all, which at TN's volumes are
 * expected to outnumber every other category. See
 * plans/tn-api-post-ingestion.md section S.4.
 */
class CoverageVerifierTest extends TestCase
{
    private const WINDOW_FROM = '2026-08-14T08:00:00Z';

    private const WINDOW_TO = '2026-08-14T10:00:00Z';

    // Deliberately far from the UK and from createTestGroup()'s London default,
    // so this test's own group is the only candidate Location::groupsNear() can
    // return and no seeded or parallel-test group can tie with it.
    private const PLACEABLE_LAT = 10.0;

    private const PLACEABLE_LNG = 10.0;

    private function verifier(): CoverageVerifier
    {
        return new CoverageVerifier();
    }

    /**
     * @param  array<string, array<string, mixed>>  $lookups  post_id => lookupPostById() result
     */
    private function syncerReturning(array $lookups): PostSyncer
    {
        $syncer = $this->getMockBuilder(PostSyncer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lookupPostById'])
            ->getMock();

        $syncer->method('lookupPostById')
            ->willReturnCallback(fn (string $postId) => $lookups[$postId]
                ?? ['status' => 'error', 'date' => null, 'outcome' => null, 'group_id' => null, 'lat' => null, 'lng' => null, 'post' => null]);

        return $syncer;
    }

    private function lookup(array $overrides = []): array
    {
        return array_merge([
            'status'   => 'found',
            'date'     => '2026-08-14T09:00:00Z',
            'outcome'  => null,
            'group_id' => null,
            'lat'      => self::PLACEABLE_LAT,
            'lng'      => self::PLACEABLE_LNG,
            'post'     => ['post_id' => 'stub'],
        ], $overrides);
    }

    /**
     * @param  array{lat?: float|null, lng?: float|null}  $overrides
     */
    private function inventoryEntry(string $postId, array $overrides = []): array
    {
        return array_merge([
            'post_id'     => $postId,
            'timestamp'   => '2026-08-14T09:00:00Z',
            'subject'     => 'OFFER: Bookshelf (Camden)',
            'lat'         => self::PLACEABLE_LAT,
            'lng'         => self::PLACEABLE_LNG,
            'envelope_to' => 'camdengroup@' . config('freegle.mail.group_domain'),
            'outcome'     => null,
            'path'        => '/tmp/fake.json',
        ], $overrides);
    }

    private function verify(array $inventory, PostSyncer $syncer): array
    {
        return $this->verifier()->verify(
            $inventory,
            $syncer,
            CarbonImmutable::parse(self::WINDOW_FROM),
            CarbonImmutable::parse(self::WINDOW_TO),
        );
    }

    public function test_a_post_with_a_messages_row_is_covered_without_an_api_call(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createTestMessage($user, $group, ['tnpostid' => 'tn-covered-1']);

        // Any lookup at all would mean we spent a rate-limited request on a post
        // we could already see in the database.
        $syncer = $this->getMockBuilder(PostSyncer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lookupPostById'])
            ->getMock();
        $syncer->expects($this->never())->method('lookupPostById');

        $result = $this->verify(['tn-covered-1' => $this->inventoryEntry('tn-covered-1')], $syncer);

        $this->assertSame(['tn-covered-1'], $result[CoverageVerifier::COVERED]);
        $this->assertSame(0, $result['api_lookups']);
    }

    public function test_a_crosspost_copy_is_expected_absent(): void
    {
        // THE case this command would otherwise drown in: TN gives each
        // per-group copy its own post id, and the API path ingests only the
        // source post (empty group_id), so N-1 copies never get a messages row.
        $this->createTestGroup(['lat' => self::PLACEABLE_LAT, 'lng' => self::PLACEABLE_LNG]);

        $syncer = $this->syncerReturning([
            'tn-copy' => $this->lookup(['group_id' => '55123']),
        ]);

        $result = $this->verify(['tn-copy' => $this->inventoryEntry('tn-copy')], $syncer);

        $this->assertSame(['tn-copy'], $result[CoverageVerifier::CROSSPOST]);
        $this->assertSame([], $result[CoverageVerifier::GENUINE]);
    }

    public function test_a_post_outside_every_group_boundary_is_expected_absent(): void
    {
        // Middle of the Atlantic — the API path dropped it as
        // not-in-any-group-bounds, which is correct, not a regression.
        $this->createTestGroup(['lat' => 51.5074, 'lng' => -0.1278]);

        $syncer = $this->getMockBuilder(PostSyncer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['lookupPostById'])
            ->getMock();
        // Resolved locally, so no request is spent on it.
        $syncer->expects($this->never())->method('lookupPostById');

        $result = $this->verify([
            'tn-oob' => $this->inventoryEntry('tn-oob', ['lat' => 35.0, 'lng' => -40.0]),
        ], $syncer);

        $this->assertSame(['tn-oob'], $result[CoverageVerifier::UNPLACEABLE]);
    }

    public function test_a_post_the_live_api_never_mapped_is_expected_absent(): void
    {
        // TN's own latitude/longitude are nullable ("may be null if a post
        // hasn't been mapped"), and PostSyncer drops such a post as
        // no-coordinates. Only the API can say so — the email header cannot.
        $this->createTestGroup(['lat' => self::PLACEABLE_LAT, 'lng' => self::PLACEABLE_LNG]);

        $syncer = $this->syncerReturning([
            'tn-unmapped' => $this->lookup(['lat' => null, 'lng' => null]),
        ]);

        $result = $this->verify(['tn-unmapped' => $this->inventoryEntry('tn-unmapped')], $syncer);

        $this->assertSame(['tn-unmapped'], $result[CoverageVerifier::UNPLACEABLE]);
        $this->assertSame([], $result[CoverageVerifier::GENUINE]);
    }

    public function test_a_post_whose_live_coordinates_moved_out_of_bounds_is_expected_absent(): void
    {
        // The header placed it in our group, but TN's current location for it is
        // in the Atlantic — and the current location is what the API path would
        // have placed it from.
        $this->createTestGroup(['lat' => self::PLACEABLE_LAT, 'lng' => self::PLACEABLE_LNG]);

        $syncer = $this->syncerReturning([
            'tn-moved' => $this->lookup(['lat' => 35.0, 'lng' => -40.0]),
        ]);

        $result = $this->verify(['tn-moved' => $this->inventoryEntry('tn-moved')], $syncer);

        $this->assertSame(['tn-moved'], $result[CoverageVerifier::UNPLACEABLE]);
    }

    public function test_a_missing_coordinates_header_is_judged_on_the_live_post_not_assumed_unplaceable(): void
    {
        // THE regression this guards: an email whose coordinates header is
        // absent (or malformed, which ArchiveInventoryService also reports as
        // null) says nothing about where TN thinks the post is. Filing it as
        // "unplaceable" without asking would bury a real coverage gap in the
        // bucket the report describes as expected.
        $this->createTestGroup(['lat' => self::PLACEABLE_LAT, 'lng' => self::PLACEABLE_LNG]);

        $syncer = $this->syncerReturning([
            'tn-noheader' => $this->lookup(['post' => ['post_id' => 'tn-noheader']]),
        ]);

        $result = $this->verify([
            'tn-noheader' => $this->inventoryEntry('tn-noheader', ['lat' => null, 'lng' => null]),
        ], $syncer);

        $this->assertArrayHasKey('tn-noheader', $result[CoverageVerifier::GENUINE]);
        $this->assertSame([], $result[CoverageVerifier::UNPLACEABLE]);
        $this->assertSame(1, $result['api_lookups']);
    }

    public function test_a_missing_coordinates_header_on_a_crosspost_copy_is_still_a_crosspost(): void
    {
        // The old short-circuit also mislabelled these: no header meant
        // "unplaceable", so the copy never reached the group_id check that
        // explains its absence.
        $this->createTestGroup(['lat' => self::PLACEABLE_LAT, 'lng' => self::PLACEABLE_LNG]);

        $syncer = $this->syncerReturning([
            'tn-copy-noheader' => $this->lookup(['group_id' => '55123']),
        ]);

        $result = $this->verify([
            'tn-copy-noheader' => $this->inventoryEntry('tn-copy-noheader', ['lat' => null, 'lng' => null]),
        ], $syncer);

        $this->assertSame(['tn-copy-noheader'], $result[CoverageVerifier::CROSSPOST]);
    }

    public function test_a_deleted_post_is_expected_absent(): void
    {
        $this->createTestGroup(['lat' => self::PLACEABLE_LAT, 'lng' => self::PLACEABLE_LNG]);

        $syncer = $this->syncerReturning([
            'tn-deleted' => ['status' => 'not_found', 'date' => null, 'outcome' => null, 'group_id' => null, 'lat' => null, 'lng' => null, 'post' => null],
        ]);

        $result = $this->verify(['tn-deleted' => $this->inventoryEntry('tn-deleted')], $syncer);

        $this->assertSame(['tn-deleted'], $result[CoverageVerifier::DELETED]);
    }

    public function test_a_resolved_post_is_expected_absent(): void
    {
        $this->createTestGroup(['lat' => self::PLACEABLE_LAT, 'lng' => self::PLACEABLE_LNG]);

        $syncer = $this->syncerReturning([
            'tn-satisfied' => $this->lookup(['outcome' => 'satisfied']),
        ]);

        $result = $this->verify(['tn-satisfied' => $this->inventoryEntry('tn-satisfied')], $syncer);

        $this->assertSame(['tn-satisfied'], $result[CoverageVerifier::RESOLVED]);
    }

    public function test_a_post_whose_date_moved_out_of_the_window_is_expected_absent(): void
    {
        // TN mutates `date` on repost/edit, so the sync covering the window the
        // email arrived in was never offered this post.
        $this->createTestGroup(['lat' => self::PLACEABLE_LAT, 'lng' => self::PLACEABLE_LNG]);

        $syncer = $this->syncerReturning([
            'tn-bumped' => $this->lookup(['date' => '2026-08-14T18:00:00Z']),
        ]);

        $result = $this->verify(['tn-bumped' => $this->inventoryEntry('tn-bumped')], $syncer);

        $this->assertSame(['tn-bumped'], $result[CoverageVerifier::BUMPED]);
    }

    public function test_an_unreachable_api_does_not_manufacture_a_miss(): void
    {
        // A TN blip must never look like a coverage gap — still less trigger a
        // backfill write.
        $this->createTestGroup(['lat' => self::PLACEABLE_LAT, 'lng' => self::PLACEABLE_LNG]);

        $syncer = $this->syncerReturning([
            'tn-error' => ['status' => 'error', 'date' => null, 'outcome' => null, 'group_id' => null, 'lat' => null, 'lng' => null, 'post' => null],
        ]);

        $result = $this->verify(['tn-error' => $this->inventoryEntry('tn-error')], $syncer);

        $this->assertSame(['tn-error'], $result[CoverageVerifier::LOOKUP_ERROR]);
        $this->assertSame([], $result[CoverageVerifier::GENUINE]);
    }

    public function test_a_live_placeable_source_post_with_no_message_is_a_genuine_miss(): void
    {
        // The case the whole command exists for.
        $this->createTestGroup(['lat' => self::PLACEABLE_LAT, 'lng' => self::PLACEABLE_LNG]);

        $syncer = $this->syncerReturning([
            'tn-missing' => $this->lookup(['post' => ['post_id' => 'tn-missing']]),
        ]);

        $result = $this->verify(['tn-missing' => $this->inventoryEntry('tn-missing')], $syncer);

        $this->assertArrayHasKey('tn-missing', $result[CoverageVerifier::GENUINE]);
        $this->assertSame(1, $result['counts'][CoverageVerifier::GENUINE]);
        $this->assertSame(['post_id' => 'tn-missing'], $result[CoverageVerifier::GENUINE]['tn-missing']['post']);
    }

    public function test_counts_cover_every_bucket_in_one_run(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup(['lat' => self::PLACEABLE_LAT, 'lng' => self::PLACEABLE_LNG]);
        $this->createTestMessage($user, $group, ['tnpostid' => 'tn-covered-2']);

        $syncer = $this->syncerReturning([
            'tn-copy-2'    => $this->lookup(['group_id' => '55123']),
            'tn-missing-2' => $this->lookup(),
        ]);

        $result = $this->verify([
            'tn-covered-2' => $this->inventoryEntry('tn-covered-2'),
            'tn-copy-2'    => $this->inventoryEntry('tn-copy-2'),
            'tn-missing-2' => $this->inventoryEntry('tn-missing-2'),
            'tn-oob-2'     => $this->inventoryEntry('tn-oob-2', ['lat' => 35.0, 'lng' => -40.0]),
        ], $syncer);

        $this->assertSame(1, $result['counts'][CoverageVerifier::COVERED]);
        $this->assertSame(1, $result['counts'][CoverageVerifier::CROSSPOST]);
        $this->assertSame(1, $result['counts'][CoverageVerifier::UNPLACEABLE]);
        $this->assertSame(1, $result['counts'][CoverageVerifier::GENUINE]);
        // Only the two placeable, uncovered posts cost a request.
        $this->assertSame(2, $result['api_lookups']);
    }
}
