<?php

namespace Tests\Unit\Services\Automod;

use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use App\Services\Automod\AutomodFactsService;
use App\Services\Automod\AutomodService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers AutomodService::review() (the single POST /review call and its
 * success/unavailable/persist-false paths) and ::process() (the Pending-candidate
 * query and loop that drives it), per plans/active/automod-flowchart.md.
 */
class AutomodServiceTest extends TestCase
{
    private AutomodService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AutomodService();

        config([
            'freegle.automod.url' => 'http://automod-test:8080',
            'freegle.automod.timeout' => 20,
            'freegle.automod.shadow_group_ids' => '',
            'freegle.autoapprove.enabled' => false,
            'freegle.autoapprove.trial_group_ids' => '',
        ]);
    }

    /**
     * Creates a user, group and Pending, content-checked message - a candidate row
     * AutomodService::process() would pick up - without seeding a messages_automod row.
     * Mirrors AutoApproveCleanServiceTest::makeApprovable()'s shape.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Group, 2: \App\Models\Message}
     */
    private function makeCandidate(array $opts = []): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup($opts['group'] ?? []);
        $this->createMembership($user, $group, $opts['membership'] ?? []);
        $message = $this->createTestMessage($user, $group, $opts['message'] ?? []);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(array_merge([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subMinutes(21),
                'contentcheck_checked_at' => now()->subMinutes(20),
                'contentcheck_reasons' => null,
                'deleted' => 0,
                'rippled_in' => 0,
            ], $opts['mg'] ?? []));

        return [$user, $group, $message];
    }

    private function fakeReviewResponse(array $body): void
    {
        Http::fake(['*/review' => Http::response(json_encode($body), 200)]);
    }

    private function automodRow(int $msgid, int $groupid)
    {
        return DB::table('messages_automod')
            ->where('msgid', $msgid)
            ->where('groupid', $groupid)
            ->first();
    }

    // --- review() -----------------------------------------------------------------

    public function test_review_records_verdict_on_success(): void
    {
        [$user, $group, $message] = $this->makeCandidate();

        $this->fakeReviewResponse([
            'version' => 'v3',
            'verdict' => MessageAutomod::VERDICT_APPROVE,
            'end' => 'APPROVE',
            'reason' => 'Trusted member, low-risk item',
            'path' => ['start', 'member_ok', 'APPROVE'],
        ]);

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        $this->assertSame(MessageAutomod::VERDICT_APPROVE, $result['verdict']);

        $row = $this->automodRow($message->id, $group->id);
        $this->assertNotNull($row);
        $this->assertSame(MessageAutomod::MODE_SHADOW, $row->mode);
        $this->assertSame('v3', $row->chart_version);
        $this->assertSame(MessageAutomod::VERDICT_APPROVE, $row->verdict);
        $this->assertSame('APPROVE', $row->end_node);

        Http::assertSent(function ($request) use ($message, $group) {
            $data = $request->data();

            return str_contains($request->url(), '/review')
                && $data['msgid'] === $message->id
                && $data['groupid'] === $group->id
                && array_key_exists('facts', $data)
                && array_key_exists('rules', $data);
        });
    }

    public function test_review_includes_backend_in_payload_when_given(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        $this->fakeReviewResponse(['version' => 'v1', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end' => 'HOLD']);

        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW, 'claude');

        Http::assertSent(fn ($request) => ($request->data()['backend'] ?? null) === 'claude');
    }

    public function test_review_omits_backend_key_when_not_given(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        $this->fakeReviewResponse(['version' => 'v1', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end' => 'HOLD']);

        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        Http::assertSent(fn ($request) => !array_key_exists('backend', $request->data()));
    }

    public function test_review_returns_null_for_missing_message(): void
    {
        Http::fake();

        $result = $this->service->review(999999999, 1, MessageAutomod::MODE_SHADOW);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_review_records_unavailable_on_http_error_status(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        Http::fake(['*/review' => Http::response('Server Error', 500)]);

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        $this->assertNull($result);
        $row = $this->automodRow($message->id, $group->id);
        $this->assertSame('UNAVAILABLE', $row->end_node);
        $this->assertSame(MessageAutomod::VERDICT_HOLD, $row->verdict);
    }

    public function test_review_records_unavailable_on_unparseable_body(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        Http::fake(['*/review' => Http::response('not json', 200)]);

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        $this->assertNull($result);
        $row = $this->automodRow($message->id, $group->id);
        $this->assertSame('UNAVAILABLE', $row->end_node);
        $this->assertSame(MessageAutomod::VERDICT_HOLD, $row->verdict);
    }

    public function test_review_records_unavailable_on_thrown_exception(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('connection refused');
        });

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        $this->assertNull($result);
        $row = $this->automodRow($message->id, $group->id);
        $this->assertSame('UNAVAILABLE', $row->end_node);
        $this->assertSame(MessageAutomod::VERDICT_HOLD, $row->verdict);
    }

    public function test_review_persist_false_does_not_write_row_on_success(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        $this->fakeReviewResponse(['version' => 'v1', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE']);

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW, null, false);

        $this->assertSame(MessageAutomod::VERDICT_APPROVE, $result['verdict']);
        $this->assertSame(0, DB::table('messages_automod')
            ->where('msgid', $message->id)->where('groupid', $group->id)->count());
    }

    public function test_review_persist_false_does_not_write_row_on_failure(): void
    {
        [$user, $group, $message] = $this->makeCandidate();
        Http::fake(['*/review' => Http::response('Server Error', 500)]);

        $result = $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW, null, false);

        $this->assertNull($result);
        $this->assertSame(0, DB::table('messages_automod')
            ->where('msgid', $message->id)->where('groupid', $group->id)->count());
    }

    public function test_review_rerun_updates_rather_than_duplicates_row(): void
    {
        [$user, $group, $message] = $this->makeCandidate();

        // Http::fake keeps the first stub that matches, so the two answers come as a sequence.
        Http::fake(['*/review' => Http::sequence()
            ->push(json_encode(['version' => 'v1', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end' => 'HOLD']), 200)
            ->push(json_encode(['version' => 'v2', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE']), 200)]);

        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);
        $this->service->review($message->id, $group->id, MessageAutomod::MODE_SHADOW);

        $this->assertSame(1, DB::table('messages_automod')
            ->where('msgid', $message->id)->where('groupid', $group->id)->count());

        $row = $this->automodRow($message->id, $group->id);
        $this->assertSame('v2', $row->chart_version);
        $this->assertSame(MessageAutomod::VERDICT_APPROVE, $row->verdict);
    }

    // --- process() ------------------------------------------------------------------

    public function test_process_returns_zero_stats_when_automod_is_dark_everywhere(): void
    {
        Http::fake();
        [$user, $group, $message] = $this->makeCandidate();

        $stats = $this->service->process();

        $this->assertSame(['reviewed' => 0, 'unavailable' => 0, 'errors' => 0], $stats);
        Http::assertNothingSent();
    }

    public function test_process_reviews_pending_candidate_with_no_existing_row(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate();
        $this->fakeReviewResponse(['version' => 'v1', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end' => 'APPROVE']);

        $stats = $this->service->process();

        $this->assertSame(1, $stats['reviewed']);
        $this->assertSame(0, $stats['unavailable']);
        $this->assertSame(0, $stats['errors']);
        $this->assertNotNull($this->automodRow($message->id, $group->id));
    }

    public function test_process_skips_existing_unedited_row(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate();
        MessageAutomod::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => 'test',
            'verdict' => MessageAutomod::VERDICT_APPROVE,
            'end_node' => 'APPROVE',
            'reason' => null,
            'path' => [],
            'created' => now(),
        ]);
        Http::fake();

        $stats = $this->service->process();

        $this->assertSame(0, $stats['reviewed']);
        Http::assertNothingSent();
    }

    public function test_process_reprocesses_row_older_than_edit(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate();
        MessageAutomod::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => 'test',
            'verdict' => MessageAutomod::VERDICT_APPROVE,
            'end_node' => 'APPROVE',
            'reason' => null,
            'path' => [],
            'created' => now()->subMinutes(30),
        ]);
        DB::table('messages')->where('id', $message->id)->update(['editedat' => now()->subMinutes(5)]);
        $this->fakeReviewResponse(['version' => 'v2', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end' => 'HOLD']);

        $stats = $this->service->process();

        $this->assertSame(1, $stats['reviewed']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/review'));
    }

    public function test_process_excludes_deleted_rows(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate(['mg' => ['deleted' => 1]]);
        Http::fake();

        $stats = $this->service->process();

        $this->assertSame(0, $stats['reviewed']);
        Http::assertNothingSent();
    }

    public function test_process_excludes_rippled_in_rows(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate(['mg' => ['rippled_in' => 1]]);
        Http::fake();

        $stats = $this->service->process();

        $this->assertSame(0, $stats['reviewed']);
        Http::assertNothingSent();
    }

    public function test_process_excludes_not_content_checked_rows(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate(['mg' => ['contentcheck_checked_at' => null]]);
        Http::fake();

        $stats = $this->service->process();

        $this->assertSame(0, $stats['reviewed']);
        Http::assertNothingSent();
    }

    public function test_process_counts_errors_when_facts_service_throws(): void
    {
        config(['freegle.autoapprove.enabled' => true]);
        [$user, $group, $message] = $this->makeCandidate();
        Http::fake();

        $service = new AutomodService(new class extends AutomodFactsService {
            public function facts(int $msgid, int $groupid): array
            {
                throw new \Exception('boom');
            }
        });

        $stats = $service->process();

        $this->assertSame(0, $stats['reviewed']);
        $this->assertSame(1, $stats['errors']);
        $this->assertSame(0, DB::table('messages_automod')
            ->where('msgid', $message->id)->where('groupid', $group->id)->count());
    }
}
