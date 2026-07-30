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
     * Uses 'MODERATED' rather than 'DEFAULT' for ourPostingStatus. A DEFAULT
     * member is a known, already-documented divergence between the two
     * paths — IncomingMailService::handleGroupPost() no longer auto-approves
     * DEFAULT posters on arrival, while GroupPostIngestionService::ingest()
     * still does — so both would route the identical post to different
     * outcomes (pending vs approved) even with byte-identical content,
     * which is not what these parity tests are about. MODERATED pends on
     * both paths, keeping outcome (and therefore Layer 3) driven purely by
     * the scenario's own fixture content, not this unrelated divergence.
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
