<?php

namespace Tests\Feature\TrashNothing;

use App\Models\Group;
use App\Models\Membership;
use App\Models\UserEmail;
use App\Services\LokiService;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Sync\EmailReplaySyncer;
use App\Services\TrashNothing\Sync\ParityComparer;
use App\Services\TrashNothing\Sync\PostSyncer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Runs the legacy email path (EmailReplaySyncer) and the new API path
 * (PostSyncer) over dedicated local fixture files and checks ParityComparer's
 * four-layer model both flags issues when they exist and stays silent when
 * they don't. See plans/tn-api-post-ingestion.md section Q and
 * ParityComparer's class docblock for the full design rationale.
 *
 * Each scenario lives in its own tests/fixtures/tn_sync/parity/<scenario>/
 * directory (fd_post_log.csv + posts_page_1.json) so the two paths can be
 * driven independently without disturbing the shared tn_sync fixtures used
 * elsewhere. Every test constructs EmailReplaySyncer/PostSyncer directly
 * (not via artisan) so the fixture path override constructor args can point
 * at the scenario's own files.
 */
class EmailApiParityTest extends TestCase
{
    private const FIXTURE_BASE = 'tests/fixtures/tn_sync/parity';
    private const WINDOW_FROM  = '2026-07-10T00:00:00Z';
    private const WINDOW_TO    = '2026-07-11T00:00:00Z';

    private int $fakeLocationId;

    private string $lokiLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        // The CSV fixtures have no secret column; EmailReplaySyncer injects the
        // configured secret so IncomingMailService::shouldSkipSpamCheck() returns
        // true (all CSV rows are treated as real TN posts that originally carried it).
        config(['freegle.mail.trashnothing_secret' => 'test-secret-12345']);

        // Both syncers resolve message location via a spatial-server KNN call;
        // fake it so every coordinate deterministically resolves to the same
        // locationid, keeping that field comparable between paths. Stored on
        // the instance (not re-queried) so seedParityUser() can't accidentally
        // pick up some other pre-existing row from the locations table.
        $this->fakeLocationId = (int) DB::table('locations')->insertGetId([
            'name' => 'TestLocation_' . uniqid('', true),
            'type' => 'Postcode',
            'lat'  => 0,
            'lng'  => 0,
        ]);
        Http::fake([
            '*/v1/postcodes/knn*' => Http::response(['results' => [['id' => $this->fakeLocationId, 'distance' => 0]]], 200),
        ]);

        // Layer 5 compares the two paths' Loki entries, which LokiService only
        // builds when enabled. Point it at a throwaway directory and bind that
        // instance so both syncers resolve the configured one — the comparison
        // reads the entries from the [LOKI] trace lines, not from these files.
        $this->lokiLogPath = sys_get_temp_dir() . '/parity-loki-' . uniqid('', true);
        mkdir($this->lokiLogPath, 0777, true);
        config(['freegle.loki.enabled' => true, 'freegle.loki.log_path' => $this->lokiLogPath]);
        $this->app->instance(LokiService::class, new LokiService);
    }

    protected function tearDown(): void
    {
        if (isset($this->lokiLogPath) && is_dir($this->lokiLogPath)) {
            foreach (glob($this->lokiLogPath . '/*.log') as $file) {
                unlink($file);
            }
            @rmdir($this->lokiLogPath);
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Layer 1 — coverage
    // -------------------------------------------------------------------------

    public function test_layer1_flags_a_post_the_api_path_never_covered(): void
    {
        $layers = $this->runScenario('layer1_missing', from: self::WINDOW_FROM, to: self::WINDOW_TO);

        $this->assertContains('tn-parity-l1-1', $layers['layer1Missing'], 'Layer 1 should flag the post the API fixture omitted entirely');
        $this->assertNotEmpty($layers['layer1Details']['tn-parity-l1-1'] ?? '', 'Layer 1 failure should carry full post detail, not just the post_id');
        $this->assertStringContainsString('post_id=tn-parity-l1-1', $layers['layer1Details']['tn-parity-l1-1']);
        $this->assertStringContainsString('email_result=', $layers['layer1Details']['tn-parity-l1-1']);
    }

    public function test_layer1_silent_when_api_path_covers_every_email_post(): void
    {
        $layers = $this->runAllCleanScenario();

        $this->assertEmpty($layers['layer1Missing'], 'Layer 1 must not flag anything when the API path covers every post the email path saw');
    }

    public function test_layer1_flags_a_post_the_api_path_could_not_place_in_any_group(): void
    {
        // The email path resolves the group from the recipient address (a real
        // group), so it successfully processes this post. The API path resolves
        // the group from coordinates, and this fixture deliberately gives it
        // coordinates far outside any seeded group's bounds, producing a
        // [POST-SKIP] reason=not-in-any-group-bounds before ingest() is ever
        // called. That pre-ingest skip must NOT count as "coverage" — the email
        // path placed the post in a real group, so a placement failure on the
        // API side is a genuine regression, not "just a non-UK post" (those
        // never reach the email path at all, since TN only emails posts to a
        // Freegle group address in the first place).
        $this->seedParityGroup('9107', 25.0000, 25.0000);

        $layers = $this->runScenario('layer1_out_of_bounds', from: self::WINDOW_FROM, to: self::WINDOW_TO);

        $this->assertContains('tn-parity-l1oob-1', $layers['layer1Missing'], 'A post the API could not place in any group must still count as a Layer 1 miss');
        $this->assertStringContainsString('api_status=skipped(not-in-any-group-bounds)', $layers['layer1Details']['tn-parity-l1oob-1'] ?? '');
    }

    // -------------------------------------------------------------------------
    // Layer 2 — extra posts (informational)
    // -------------------------------------------------------------------------

    public function test_layer2_flags_extra_api_only_posts_without_failing(): void
    {
        $this->seedParityGroup('9103', 22.0000, 22.0000);

        $layers = $this->runScenario('layer2_extra', from: self::WINDOW_FROM, to: self::WINDOW_TO);

        $this->assertContains('tn-parity-l2-extra', $layers['layer2Extra'], 'Layer 2 should flag the API-only post');
        $this->assertEmpty($layers['layer1Missing'], 'The shared post_id must not register as a Layer 1 miss');
        // Layer 2 is informational only — never a Layer 1/3 failure by itself.
        $this->assertEmpty($layers['layer3Mismatches']);
    }

    public function test_layer2_silent_when_api_path_covers_no_extra_posts(): void
    {
        $layers = $this->runAllCleanScenario();

        $this->assertEmpty($layers['layer2Extra'], 'Layer 2 must not flag anything when the API path saw nothing beyond the email path');
    }

    // -------------------------------------------------------------------------
    // Crossposts — TN per-group copies, discarded at ingestion, excluded here
    // -------------------------------------------------------------------------

    public function test_discarded_crossposts_are_excluded_from_every_count(): void
    {
        // Fed straight to ParityComparer rather than through a fixture: this is
        // about how a result=crosspost line is counted, not about ingestion.
        $emailLines = [
            'TN-SYNC-TRACE [EMAIL-RESULT] post_id=tn-source-1 result=Approved',
        ];
        $apiLines = [
            'TN-SYNC-TRACE [POST-RESULT] post_id=tn-source-1 result=approved',
            'TN-SYNC-TRACE [POST-RESULT] post_id=tn-copy-1 result=crosspost',
            'TN-SYNC-TRACE [POST-RESULT] post_id=tn-copy-2 result=crosspost',
        ];

        $layers = (new ParityComparer())->computeLayers($emailLines, $apiLines);

        $this->assertSame(['tn-copy-1', 'tn-copy-2'], $layers['apiCrosspostsDiscarded']);
        $this->assertEmpty($layers['layer2Extra'], 'Discarded copies must not read as extra posts the API path found');
        $this->assertSame(1, $layers['apiCoveredCount'], 'Only the ingested source post counts as covered');
        $this->assertEmpty($layers['layer1Missing']);

        // tn-source-1 itself does land in Layer 4 here: these synthetic lines
        // carry no [WRITE] table=messages rows, and Layer 4 is exactly where a
        // pair with no messages row on either side is reported. What matters
        // for this test is that neither discarded copy appears there.
        $layer4 = implode("\n", $layers['layer4Divergences']);
        $this->assertStringNotContainsString('tn-copy-1', $layer4);
        $this->assertStringNotContainsString('tn-copy-2', $layer4);
    }

    public function test_reposts_are_still_counted_as_genuine_extra_posts(): void
    {
        // A repost is a new SOURCE post, kept by production as its own message
        // (matching the email path). The old heuristic dedup collapsed these by
        // subject+coordinates; they must now be counted in full.
        $emailLines = [
            'TN-SYNC-TRACE [EMAIL-RESULT] post_id=tn-source-1 result=Approved',
        ];
        $apiLines = [
            'TN-SYNC-TRACE [POST-RESULT] post_id=tn-source-1 result=approved',
            'TN-SYNC-TRACE [POST-RESULT] post_id=tn-repost-1 result=approved',
        ];

        $layers = (new ParityComparer())->computeLayers($emailLines, $apiLines);

        $this->assertEmpty($layers['apiCrosspostsDiscarded']);
        $this->assertContains('tn-repost-1', $layers['layer2Extra']);
        $this->assertContains('tn-repost-1', $layers['layer2ExtraIngested'], 'A repost really does land on FD, so it counts toward ingestion gain');
        $this->assertSame(2, $layers['apiIngestedCount']);
    }

    // -------------------------------------------------------------------------
    // Layer 3 — same-group parity
    // -------------------------------------------------------------------------

    public function test_layer3_flags_same_group_content_mismatch(): void
    {
        $group = $this->seedParityGroup('9104', 23.0000, 23.0000);
        $this->seedParityUser(88020005, 'parityl3@user.trashnothing.com', $group->id);

        $layers = $this->runScenario('layer3_mismatch', from: self::WINDOW_FROM, to: self::WINDOW_TO);

        $this->assertNotEmpty($layers['layer3Mismatches'], 'Layer 3 should flag the subject mismatch on the same-group post');
        $this->assertStringContainsString('tn-parity-l3-1', $layers['layer3Mismatches'][0]);
        $this->assertStringContainsString('subject:', $layers['layer3Mismatches'][0]);
        $this->assertEmpty($layers['layer4Divergences'], 'A same-group mismatch is a Layer 3 concern, not Layer 4');
    }

    public function test_layer3_silent_when_same_group_content_matches(): void
    {
        $layers = $this->runAllCleanScenario();

        $this->assertEmpty($layers['layer3Mismatches'], 'Layer 3 must not flag anything when both paths agree on group and content');
    }

    // -------------------------------------------------------------------------
    // Layer 4 — different-group divergence (informational)
    // -------------------------------------------------------------------------

    public function test_layer4_flags_divergent_group_resolution(): void
    {
        $groupEmail = $this->seedParityGroup('9105', 24.0000, 24.0000);
        // Far enough away that groupsNear() unambiguously resolves the API-side
        // post to a different group than the one the email path's recipient
        // address routes to.
        $groupApi = $this->seedParityGroup('9106', -40.0000, 150.0000);
        $this->seedParityUser(88020006, 'parityl4@user.trashnothing.com', $groupEmail->id);

        $layers = $this->runScenario('layer4_divergent_group', from: self::WINDOW_FROM, to: self::WINDOW_TO);

        $this->assertNotEmpty($layers['layer4Divergences'], 'Layer 4 should flag the group divergence');
        $this->assertStringContainsString('tn-parity-l4-1', $layers['layer4Divergences'][0]);
        $this->assertStringContainsString((string) $groupEmail->id, $layers['layer4Divergences'][0]);
        $this->assertStringContainsString((string) $groupApi->id, $layers['layer4Divergences'][0]);
        $this->assertEmpty($layers['layer3Mismatches'], 'A group divergence must not also be reported as a same-group content mismatch');
    }

    public function test_layer4_silent_when_both_paths_resolve_the_same_group(): void
    {
        $layers = $this->runAllCleanScenario();

        $this->assertEmpty($layers['layer4Divergences'], 'Layer 4 must not flag anything when both paths resolve the same group');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Seeds the group + user needed for the "all_clean" fixture (a single
     * post that both paths resolve to the same group, same user, same
     * content, routing straight to "approved" on both) and runs it,
     * returning the four-layer comparison. Shared by every "silent" test.
     */
    private function runAllCleanScenario(): array
    {
        $group = $this->seedParityGroup('9101', 20.0000, 20.0000);
        $this->seedParityUser(88020001, 'parityclean@user.trashnothing.com', $group->id);

        return $this->runScenario('all_clean', from: self::WINDOW_FROM, to: self::WINDOW_TO);
    }

    /**
     * Runs both paths against the named scenario's fixture directory and
     * returns ParityComparer::computeLayers()'s result.
     */
    // -------------------------------------------------------------------------
    // Layer 5 — Loki entry parity
    //
    // Layers 1-4 compare what each path wrote to the DATABASE. Layer 5 compares
    // what each path reported to LOKI, which is a separate contract: the two
    // streams are queried together, so a shape difference breaks the comparison
    // even when the ingestion itself was correct.
    // -------------------------------------------------------------------------

    public function test_layer5_silent_when_both_paths_report_the_same_loki_entry(): void
    {
        $layers = $this->runAllCleanScenario();

        $this->assertGreaterThan(0, $layers['lokiEntriesSeen'], 'Both paths must emit Loki entries for Layer 5 to mean anything');
        $this->assertGreaterThan(0, $layers['layer5Compared'], 'Layer 5 should have compared at least one overlapping post');
        $this->assertEmpty($layers['layer5Mismatches'], 'Layer 5 must be silent when both paths report identical entries');
    }

    public function test_layer5_flags_a_differing_field_between_the_two_entries(): void
    {
        // Driven by synthetic trace lines rather than fixtures: making the two
        // real paths disagree on a Loki field would mean deliberately breaking
        // one of them, whereas the point here is the comparison logic itself.
        $layers = (new ParityComparer())->computeLayers(
            $this->syntheticLines('email', 'incoming_mail', ['subject' => 'OFFER: Sofa']),
            $this->syntheticLines('api', 'tn_api', ['subject' => 'OFFER: Something else']),
        );

        $this->assertNotEmpty($layers['layer5Mismatches']);
        $this->assertStringContainsString('subject differs', $layers['layer5Mismatches'][0]);
    }

    public function test_layer5_flags_a_label_difference(): void
    {
        // subtype is a label as well as a message field, so a routing-outcome
        // divergence shows up as both — the label check is what guarantees the
        // two streams stay queryable as one.
        $layers = (new ParityComparer())->computeLayers(
            $this->syntheticLines('email', 'incoming_mail', ['routing_outcome' => 'Pending'], subtype: 'Pending'),
            $this->syntheticLines('api', 'tn_api', ['routing_outcome' => 'Approved'], subtype: 'Approved'),
        );

        $this->assertNotEmpty($layers['layer5Mismatches']);
        $this->assertStringContainsString('labels differ', implode(' | ', $layers['layer5Mismatches']));
    }

    public function test_layer5_flags_a_post_only_one_path_reported_to_loki(): void
    {
        $emailLines = $this->syntheticLines('email', 'incoming_mail', []);
        // API side handles the post but emits no Loki entry at all.
        $apiLines = array_values(array_filter(
            $this->syntheticLines('api', 'tn_api', []),
            fn (string $line) => !str_contains($line, '[LOKI]'),
        ));

        $layers = (new ParityComparer())->computeLayers($emailLines, $apiLines);

        $this->assertNotEmpty($layers['layer5Mismatches']);
        $this->assertStringContainsString('only the email path emitted a Loki entry', $layers['layer5Mismatches'][0]);
    }

    public function test_layer5_ignores_the_fields_that_differ_by_design(): void
    {
        // envelope_from/from_address/message_id differ between the paths for
        // structural reasons (no SMTP envelope; separate messages rows), and
        // tn_post_id is API-only. None may be reported as a mismatch.
        $layers = (new ParityComparer())->computeLayers(
            $this->syntheticLines('email', 'incoming_mail', [
                'envelope_from' => 'poster@example.com',
                'from_address'  => 'poster@example.com',
                'message_id'    => 111,
            ]),
            $this->syntheticLines('api', 'tn_api', [
                'envelope_from' => '',
                'from_address'  => '',
                'message_id'    => 222,
                'tn_post_id'    => 'tn-l5-1',
            ]),
        );

        $this->assertEmpty($layers['layer5Mismatches'], 'By-design differences must not be reported: ' . implode(' | ', $layers['layer5Mismatches']));
    }

    public function test_layer5_does_not_flag_divergent_outcomes_that_layer4_already_explains(): void
    {
        // Regression for a false positive found on live data (2026-08-04
        // window, post_id=47102958). The post was already ingested by an
        // earlier run, so the email path took its duplicate-messageid branch
        // (case 10: keeps group/user context, omits message_id, still reports
        // Pending) while the API path took its postAlreadyExists() branch
        // (routing_reason only, Dropped). Both are correct and both mirror the
        // email path's own context rules — but comparing label VALUES and
        // message KEY SETS unconditionally reported them as a Loki failure.
        //
        // Layer 4 already reports this pair as a divergence; Layer 5 must not
        // double-report it as a hard failure.
        $emailLines = array_merge(
            $this->syntheticLines('email', 'incoming_mail', [
                'group_id' => 7,
                'group_name' => 'testgroup',
                'user_id' => 42,
            ], subtype: 'Pending'),
            [],
        );

        // API side: dropped as a duplicate, so no messages row at all and a
        // context of routing_reason only.
        $apiEntry = [
            'timestamp' => '2026-07-10T12:00:00+00:00',
            'labels' => ['app' => 'freegle', 'source' => 'tn_api', 'type' => 'routed', 'subtype' => 'Dropped'],
            'message' => [
                'envelope_from' => '',
                'envelope_to' => 'testgroup@groups.ilovefreegle.org',
                'from_address' => '',
                'subject' => 'OFFER: Sofa',
                'message_id' => 'tn-l5-1@tn.trashnothing.com-7',
                'routing_outcome' => 'Dropped',
                'routing_reason' => 'duplicate',
                'tn_post_id' => 'tn-l5-1',
            ],
        ];
        $apiLines = [
            'TN-SYNC-TRACE [POST-RESULT] post_id=tn-l5-1 result=duplicate',
            'TN-SYNC-TRACE [LOKI] post_id=tn-l5-1 entry=' . json_encode($apiEntry),
        ];

        $layers = (new ParityComparer())->computeLayers($emailLines, $apiLines);

        $this->assertEmpty(
            $layers['layer5Mismatches'],
            'Layer 5 must not report a pair whose outcomes legitimately diverged: ' . implode(' | ', $layers['layer5Mismatches']),
        );
        $this->assertSame(1, $layers['layer5StructureOnly'], 'The pair should have been structure-checked only');
        $this->assertNotEmpty($layers['layer4Divergences'], 'Layer 4 is where this pair belongs');
    }

    public function test_layer5_still_flags_structural_breakage_on_a_divergent_pair(): void
    {
        // The structural checks are outcome-independent, so they must still
        // fire even for a pair Layer 5 only structure-checks. Here the API
        // entry's subtype label contradicts its own routing_outcome field — a
        // dashboard filtering on one and reading the other would disagree.
        $emailLines = $this->syntheticLines('email', 'incoming_mail', [], subtype: 'Pending');

        $apiEntry = [
            'timestamp' => '2026-07-10T12:00:00+00:00',
            'labels' => ['app' => 'freegle', 'source' => 'tn_api', 'type' => 'routed', 'subtype' => 'Dropped'],
            'message' => [
                'envelope_from' => '', 'envelope_to' => 'g@x', 'from_address' => '',
                'subject' => 'OFFER: Sofa', 'message_id' => 1,
                'routing_outcome' => 'Approved',  // contradicts the subtype label
            ],
        ];
        $apiLines = [
            'TN-SYNC-TRACE [POST-RESULT] post_id=tn-l5-1 result=duplicate',
            'TN-SYNC-TRACE [LOKI] post_id=tn-l5-1 entry=' . json_encode($apiEntry),
        ];

        $layers = (new ParityComparer())->computeLayers($emailLines, $apiLines);

        $this->assertStringContainsString(
            'does not match routing_outcome',
            implode(' | ', $layers['layer5Mismatches']),
        );
    }

    public function test_layer5_does_not_flag_an_outcome_difference_on_a_stub_created_poster(): void
    {
        // Regression for a false positive found on live data (2026-08-08
        // window): four posts reported `email=Pending api=Approved` as Layer 5
        // failures while Layer 3 reported zero mismatches for the same posts.
        //
        // The cause is the identity divergence classifyOverlapPost() already
        // gates `result` on: the API path resolved TN's fd_user_id to a real,
        // already-mapped Freegle account (so normal routing applied, Approved)
        // while the email path — which has only an address to go on — had to
        // stub-create a fresh, unmapped user, which forces Pending. Layer 3
        // excuses that; Layer 5 must excuse it too, on both copies of the
        // outcome (the `subtype` label and the `routing_outcome` field), or a
        // wide sweep drowns in failures Layer 3 has already dismissed.
        $emailLines = array_merge(
            ['TN-SYNC-TRACE [WRITE] table=users op=insert set=id=42,fullname=stub,added=now() (email-replay stub)'],
            $this->syntheticLines('email', 'incoming_mail', [], subtype: 'Pending'),
        );
        $apiLines = $this->syntheticLines('api', 'tn_api', [], subtype: 'Approved');

        $layers = (new ParityComparer())->computeLayers($emailLines, $apiLines);

        $this->assertEmpty(
            $layers['layer3Mismatches'],
            'Layer 3 already excuses a stub-created poster: ' . implode(' | ', $layers['layer3Mismatches']),
        );
        $this->assertEmpty(
            $layers['layer5Mismatches'],
            'Layer 5 must apply the same gate as Layer 3: ' . implode(' | ', $layers['layer5Mismatches']),
        );
    }

    public function test_layer5_still_flags_an_outcome_difference_on_a_resolved_poster(): void
    {
        // The gate above is narrow: with no stub-creation on either side, both
        // paths resolved the same pre-existing user, so a routing difference is
        // genuine signal and must still fail.
        $layers = (new ParityComparer())->computeLayers(
            $this->syntheticLines('email', 'incoming_mail', [], subtype: 'Pending'),
            $this->syntheticLines('api', 'tn_api', [], subtype: 'Approved'),
        );

        $this->assertStringContainsString(
            'routing_outcome differs',
            implode(' | ', $layers['layer5Mismatches']),
        );
    }

    public function test_synonymous_subject_type_prefixes_are_not_a_mismatch(): void
    {
        // The email path keeps the prefix TN put in the email subject (what the
        // member typed), the API path always synthesizes it from TN's `type`
        // field — so the same post is "OFFERED: X" on one side and "OFFER: X"
        // on the other. Both parse to the same Message type, `type` is compared
        // separately, and left unnormalized this fails Layers 3 AND 5 on every
        // such post (2 of 31 overlapping posts in one live hour).
        $layers = (new ParityComparer())->computeLayers(
            $this->syntheticLines('email', 'incoming_mail', ['subject' => 'OFFERED: Mens XL work trousers']),
            $this->syntheticLines('api', 'tn_api', ['subject' => 'OFFER: Mens XL work trousers']),
        );

        $this->assertEmpty($layers['layer5Mismatches'], 'Layer 5: ' . implode(' | ', $layers['layer5Mismatches']));
        $this->assertEmpty($layers['layer3Mismatches'], 'Layer 3: ' . implode(' | ', $layers['layer3Mismatches']));
    }

    public function test_a_subject_only_mismatch_is_offered_for_tn_edit_reclassification(): void
    {
        // A subject-only disagreement is the shape of a TN-side title edit, so
        // it is handed to TNParityCheckCommand::reclassifySubjectMismatches()
        // to confirm against TN. It still fails both layers here — the comparer
        // only nominates the candidate, it never excuses it on its own.
        $layers = (new ParityComparer())->computeLayers(
            $this->syntheticLines('email', 'incoming_mail', ['subject' => 'OFFER: Table frame']),
            $this->syntheticLines('api', 'tn_api', ['subject' => 'OFFER: Table frame & glass top']),
        );

        $this->assertSame(['tn-l5-1'], $layers['subjectOnlyMismatchPostIds']);
        $this->assertCount(1, $layers['layer3Mismatches'], 'the comparer must still report it');
        $this->assertCount(1, $layers['layer5Mismatches'], 'the comparer must still report it');
    }

    public function test_a_mismatch_beyond_the_subject_is_not_offered_for_reclassification(): void
    {
        // "TN edited the title" explains a subject difference and nothing else,
        // so a post that also disagrees on its routing outcome must never be
        // reclassified — that would swallow a genuine regression.
        $layers = (new ParityComparer())->computeLayers(
            $this->syntheticLines('email', 'incoming_mail', ['subject' => 'OFFER: A'], subtype: 'Pending'),
            $this->syntheticLines('api', 'tn_api', ['subject' => 'OFFER: B'], subtype: 'Approved'),
        );

        $this->assertEmpty($layers['subjectOnlyMismatchPostIds']);
        $this->assertNotEmpty($layers['layer5Mismatches']);
    }

    public function test_a_clean_run_nominates_nothing_for_reclassification(): void
    {
        $layers = (new ParityComparer())->computeLayers(
            $this->syntheticLines('email', 'incoming_mail', []),
            $this->syntheticLines('api', 'tn_api', []),
        );

        $this->assertEmpty($layers['subjectOnlyMismatchPostIds']);
    }

    public function test_a_genuinely_different_subject_is_still_a_mismatch(): void
    {
        // Normalizing the prefix must not swallow a real content difference —
        // e.g. a post whose title was edited on TN after its email went out
        // (observed live: "Table frame" vs "Table frame & glass top").
        $layers = (new ParityComparer())->computeLayers(
            $this->syntheticLines('email', 'incoming_mail', ['subject' => 'OFFER: Table frame']),
            $this->syntheticLines('api', 'tn_api', ['subject' => 'OFFER: Table frame & glass top']),
        );

        $this->assertStringContainsString('subject differs', implode(' | ', $layers['layer5Mismatches']));
        $this->assertStringContainsString('subject:', implode(' | ', $layers['layer3Mismatches']));
    }

    /**
     * Minimal trace lines for one post: an outcome, a messages-row write (so
     * the pair counts as same-group and therefore value-comparable), and the
     * Loki entry itself.
     *
     * @param  array<string, mixed>  $messageOverrides  merged into the entry's message
     * @return string[]
     */
    private function syntheticLines(string $side, string $source, array $messageOverrides, string $subtype = 'Pending'): array
    {
        $postId = 'tn-l5-1';
        $resultTag = $side === 'email' ? 'EMAIL-RESULT' : 'POST-RESULT';

        $entry = [
            'timestamp' => '2026-07-10T12:00:00+00:00',
            'labels' => [
                'app' => 'freegle',
                'source' => $source,
                'type' => 'routed',
                'subtype' => $subtype,
            ],
            'message' => array_merge([
                'envelope_from' => '',
                'envelope_to' => 'testgroup@groups.ilovefreegle.org',
                'from_address' => '',
                'subject' => 'OFFER: Sofa',
                'message_id' => 1,
                'routing_outcome' => $subtype,
                'group_id' => 7,
                'group_name' => 'testgroup',
                'user_id' => 42,
            ], $messageOverrides),
        ];

        return [
            "TN-SYNC-TRACE [{$resultTag}] post_id={$postId} result={$subtype}",
            // The messages row carries the same subject as the Loki entry, as
            // it does on both real paths — so a subject override exercises the
            // Layer 3 comparison as well as the Layer 5 one.
            'TN-SYNC-TRACE [WRITE] table=messages op=insert set=' . json_encode([
                'tnpostid' => $postId,
                'groupid' => 7,
                'fromuser' => 42,
                'subject' => $entry['message']['subject'],
            ]),
            "TN-SYNC-TRACE [LOKI] post_id={$postId} entry=" . json_encode($entry),
        ];
    }

    private function runScenario(string $scenario, string $from, string $to): array
    {
        $fixtureDir = self::FIXTURE_BASE . '/' . $scenario;

        $emailLines = $this->runEmailPath($fixtureDir . '/fd_post_log.csv');
        $apiLines   = $this->runApiPath($fixtureDir, $from, $to);

        return (new ParityComparer())->computeLayers($emailLines, $apiLines);
    }

    /**
     * @return string[] captured trace lines
     */
    private function runEmailPath(string $fixtureCsvPath): array
    {
        return $this->captureTraceLogs(function () use ($fixtureCsvPath) {
            $syncer = new EmailReplaySyncer(
                localTesting: true,
                loki: app(LokiService::class),
                parser: app(MailParserService::class),
                mailService: app(IncomingMailService::class),
                fixtureCsvPath: $fixtureCsvPath,
            );
            $syncer->sync();
        });
    }

    /**
     * @return string[] captured trace lines
     */
    private function runApiPath(string $fixtureDir, string $from, string $to): array
    {
        return $this->captureTraceLogs(function () use ($fixtureDir, $from, $to) {
            $syncer = new PostSyncer(
                dryRun: false,
                localTesting: true,
                apiKey: '',
                apiBaseUrl: '',
                loki: app(LokiService::class),
                fixtureDir: $fixtureDir,
            );
            $syncer->sync($from, $to);
        });
    }

    /**
     * Runs $callback inside a rolled-back (save-pointed, since the outer
     * DatabaseTransactions trait already wraps the whole test method)
     * transaction, capturing every TN-SYNC-TRACE log line
     * ParityComparer::isRelevantTraceLine() cares about.
     *
     * @return string[]
     */
    private function captureTraceLogs(\Closure $callback): array
    {
        $lines    = [];
        $listener = function ($message) use (&$lines) {
            $text = (string) $message->message;
            if (ParityComparer::isRelevantTraceLine($text)) {
                $lines[] = $text;
            }
        };

        Log::listen($listener);

        DB::beginTransaction();
        try {
            $callback();
        } finally {
            DB::rollBack();
        }

        return $lines;
    }

    private function seedParityGroup(string $nameshort, float $lat, float $lng): Group
    {
        return Group::firstOrCreate(['nameshort' => $nameshort], [
            'namefull' => 'TN Parity Test Group ' . $nameshort,
            'type'     => Group::TYPE_FREEGLE,
            'region'   => 'TestRegion',
            'lat'      => $lat,
            'lng'      => $lng,
            'onhere'   => 1,
            'publish'  => 1,
        ]);
    }

    /**
     * Uses 'MODERATED' rather than 'DEFAULT' for ourPostingStatus. Both paths now
     * pend a DEFAULT poster as well — neither approves on arrival, they wait for
     * messages:contentcheck — so either status routes identically; the divergence
     * that originally forced this choice (the API path approving DEFAULT posters
     * while the email path pended them) is fixed. MODERATED is kept because it pins
     * the outcome on the posting status alone, so outcome (and therefore Layer 3)
     * stays driven by the scenario's own fixture content rather than by whatever the
     * content check would later make of it.
     */
    private function seedParityUser(int $id, string $email, int $groupId): void
    {
        DB::table('users')->insert([
            'id'           => $id,
            'fullname'     => 'TN Parity Test User ' . $id,
            'systemrole'   => 'User',
            'added'        => now(),
            'lastlocation' => $this->fakeLocationId,
        ]);

        UserEmail::create([
            'userid'    => $id,
            'email'     => $email,
            'preferred' => 1,
            'added'     => now(),
            'canon'     => $email,
        ]);

        Membership::create([
            'userid'           => $id,
            'groupid'          => $groupId,
            'role'             => Membership::ROLE_MEMBER,
            'collection'       => Membership::COLLECTION_APPROVED,
            'emailfrequency'   => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'ourPostingStatus' => 'MODERATED',
            'added'            => now(),
        ]);
    }
}
