<?php

namespace Tests\Feature\Ux;

use App\Models\Group;
use App\Services\Ux\UxArm;
use App\Services\Ux\UxExperimentRefused;
use App\Services\Ux\UxExperimentService;
use App\Support\ExperimentBucket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * The confirm-reply experiment as the engine runs it: the create commands of the pull request, the start and its
 * refusals, the slot, real rows counted for each arm, the guardrails that revert it early, and a stop. The source
 * that counts it is tested alone in ConfirmReplySourceTest; the Go code that writes the rows is tested in
 * iznik-server-go/test/chatreplyconfirm_test.go.
 *
 * The time is 11:30 UTC on Tuesday 20 October 2026. The batch has no switch list entry for this experiment, because
 * no batch code reads its arm: the Go API does.
 *
 * A helper here must not be called post, get, put or delete: the base test case has public methods of those names, and
 * a clash is a fatal error that the status API reports as "0 of 0 tests".
 */
class ConfirmReplyExperimentTest extends TestCase
{
    use FakesUxLoki;

    private const NAME = 'confirm-reply';

    private const PR = 'https://github.com/Freegle/Iznik/pull/1767';

    /**
     * The flags the two create scripts of the pull request share, as the pull request prints them. The pull request
     * repeats these and the designs below: change them together.
     */
    private const CREATE = [
        '--flow' => 'reply',
        '--name' => self::NAME,
        '--title' => 'Ask before passing on a reply to a damaged or bulky offer',
        '--hypothesis' => 'Some people reply without noticing that the item looks damaged or needs a car. If we ask first, the poster gets fewer replies from people who then do not want it.',
        '--change' => "Half of the people who reply to such an offer are asked 'Do you still want it?' and their reply waits for the answer. The other half are not asked.",
        '--arms' => '{"control":[0,49],"ask":[50,99]}',
        '--pr' => self::PR,
    ];

    private const COPY = '{"control":"The reply goes straight to the poster, as it does now.","ask":"Freegle asks the replier: Do you still want it? The reply goes to the poster after a Yes. It is dropped after a No, or after 3 days with no answer."}';

    /** The first script: decided on fewer wasted replies, with handovers watched. */
    private const EXPECTED_NO_WASTED_REPLY = 'Fewer replies reach the poster from people who no longer want the item, and about the same share of repliers are promised the item or take it.';

    private const DESIGN_NO_WASTED_REPLY = '{"channel":"server","source":"confirm_reply","window_hours":168,"intake_hours":168,"max_hours":336,"primary":"no_wasted_reply","expected_points":10,"slot":"server:confirm-reply","copy":'.self::COPY.'}';

    /** The second script: decided on handovers. */
    private const EXPECTED_HANDOVER = 'About the same share of repliers are promised the item or take it, and the poster reads fewer replies from people who no longer want it.';

    private const DESIGN_HANDOVER = '{"channel":"server","source":"confirm_reply","window_hours":168,"intake_hours":168,"max_hours":336,"primary":"handover","expected_points":3,"slot":"server:confirm-reply","copy":'.self::COPY.'}';

    private UxExperimentService $service;

    private ?Group $group = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 11:30:00');
        UxArm::flush();
        $this->fakeLoki();
        $this->service = app(UxExperimentService::class);
        // Rows from other work in the same database must not count here or hold the slot.
        DB::table('ux_experiment_reads')->delete();
        DB::table('ux_experiments')->delete();
        DB::table('config')->whereIn('key', [UxExperimentService::CONFIG_KEY, UxArm::SWITCH_KEY])->delete();
        // The size of a run is read from whatever replies the database holds. Only the test of the power check asks for it.
        config()->set('freegle.ux.experiments.min_power', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        UxArm::flush();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function createDraft(string $script = 'no_wasted_reply'): int
    {
        $flags = $script === 'no_wasted_reply'
            ? ['--expected' => self::EXPECTED_NO_WASTED_REPLY, '--design' => self::DESIGN_NO_WASTED_REPLY]
            : ['--expected' => self::EXPECTED_HANDOVER, '--design' => self::DESIGN_HANDOVER];

        $this->artisan('ux:experiment', ['action' => 'create'] + $flags + self::CREATE)
            ->expectsOutputToContain('Created experiment')
            ->assertExitCode(0);

        return (int) DB::table('ux_experiments')->where('name', self::NAME)->value('id');
    }

    private function switchServerArmsOn(): void
    {
        DB::table('config')->upsert([['key' => UxArm::SWITCH_KEY, 'value' => '1']], ['key'], ['value']);
        UxArm::flush();
    }

    private function startTheExperiment(string $script = 'no_wasted_reply'): object
    {
        $id = $this->createDraft($script);
        $this->switchServerArmsOn();

        return $this->service->start($id);
    }

    private function refusal(callable $work): string
    {
        try {
            $work();
        } catch (UxExperimentRefused $e) {
            return $e->getMessage();
        }
        $this->fail('Expected the start to be refused');
    }

    private function group(): Group
    {
        return $this->group ??= $this->createTestGroup();
    }

    /**
     * Cohorts of repliers with their first replies and exposures, written in bulk because the early rules need 300 counted
     * on each arm. The first $handed repliers were promised the post, the next $dropped had their reply dropped by the
     * question (the batch marks it not confirmed), and the rest were delivered with no handover.
     */
    private function seedCohort(string $arm, int $n, int $handed, int $dropped, string $at = '2026-10-20 12:00:00'): void
    {
        $poster = $this->createTestUser();
        $post = $this->createTestMessage($poster, $this->group());
        $first = $this->createTestUser();
        $room = $this->createTestChatRoom($first, $poster);

        $ids = [$first->id];
        for ($i = 1; $i < $n; $i++) {
            $ids[] = DB::table('users')->insertGetId(['firstname' => 'Cohort', 'lastname' => 'Member', 'fullname' => 'Cohort Member', 'added' => $at]);
        }

        $messages = [];
        foreach ($ids as $i => $userid) {
            $isDropped = $i >= $handed && $i < $handed + $dropped;
            $messages[] = [
                'chatid' => $room->id, 'userid' => $userid, 'type' => 'Interested', 'refmsgid' => $post->id, 'date' => $at, 'message' => 'Is it still there?',
                'platform' => 1, 'processingrequired' => 0, 'processingsuccessful' => $isDropped ? 0 : 1,
                'processingfailreason' => $isDropped ? 'ReplyNotConfirmed' : null,
            ];
        }
        foreach (array_chunk($messages, 150) as $chunk) {
            DB::table('chat_messages')->insert($chunk);
        }
        $replies = DB::table('chat_messages')->where('chatid', $room->id)->whereIn('userid', $ids)->pluck('id', 'userid');

        $exposures = [];
        $promises = [];
        foreach ($ids as $i => $userid) {
            $exposures[] = [
                'experiment' => self::NAME, 'arm' => $arm, 'userid' => $userid, 'subject_type' => 'reply', 'subject_id' => $replies[$userid],
                'stratum' => 'damaged', 'exposed_at' => $at, 'detail' => null,
            ];
            if ($i < $handed) {
                $promises[] = ['msgid' => $post->id, 'userid' => $userid, 'promisedat' => Carbon::parse($at, 'UTC')->addHours(2)->format('Y-m-d H:i:s')];
            }
        }
        foreach (array_chunk($exposures, 150) as $chunk) {
            DB::table('ux_experiment_exposures')->insert($chunk);
        }
        foreach (array_chunk($promises, 150) as $chunk) {
            DB::table('messages_promises')->insert($chunk);
        }
    }

    // ---------------------------------------------------------------- the create commands

    public function test_the_first_create_command_of_the_pull_request_makes_the_draft_decided_on_no_wasted_reply(): void
    {
        $id = $this->createDraft('no_wasted_reply');

        $row = DB::table('ux_experiments')->where('id', $id)->first();
        $this->assertSame('draft', $row->status);
        $this->assertSame('reply', $row->flow);
        $this->assertSame(self::NAME, $row->name);
        $this->assertSame(self::PR, $row->pr_url);
        $this->assertSame(self::EXPECTED_NO_WASTED_REPLY, $row->expected);
        // A JSON column keeps its keys in its own order, so the arms and the design are compared as maps.
        $this->assertEquals(['control' => [0, 49], 'ask' => [50, 99]], json_decode($row->arms, true));

        $design = json_decode($row->design, true);
        $this->assertEquals([
            'channel' => 'server', 'source' => 'confirm_reply', 'window_hours' => 168, 'intake_hours' => 168, 'max_hours' => 336,
            'primary' => 'no_wasted_reply', 'expected_points' => 10, 'slot' => 'server:confirm-reply', 'fixed_split' => true,
            'copy' => json_decode(self::COPY, true),
        ], $design);
        $this->assertArrayNotHasKey('type', $design, 'the slot is the design\'s own, not <channel>:<type>');
        $this->assertArrayNotHasKey('guardrail', $design, 'there is no such design key: the guardrail comes from the source and its primary');
        foreach ($design['copy'] as $text) {
            $this->assertLessThanOrEqual(240, mb_strlen($text));
        }
        foreach (array_merge(array_values(self::CREATE), [self::EXPECTED_NO_WASTED_REPLY], array_values($design['copy'])) as $text) {
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $text, 'no long dash in words staff read');
        }
    }

    public function test_the_second_create_command_makes_the_draft_decided_on_handover(): void
    {
        $id = $this->createDraft('handover');

        $row = DB::table('ux_experiments')->where('id', $id)->first();
        $design = json_decode($row->design, true);
        $this->assertSame('handover', $design['primary']);
        $this->assertEquals(3, $design['expected_points']);
        $this->assertSame('server:confirm-reply', $design['slot']);
        $this->assertSame(self::EXPECTED_HANDOVER, $row->expected);
        $this->assertEquals(json_decode(self::DESIGN_NO_WASTED_REPLY, true)['copy'], $design['copy'], 'the same words for the two versions');

        // The two scripts differ in one word and what follows from it.
        $first = json_decode(self::DESIGN_NO_WASTED_REPLY, true);
        $second = json_decode(self::DESIGN_HANDOVER, true);
        $differs = array_keys(array_filter($first, fn ($value, $key) => $second[$key] !== $value, ARRAY_FILTER_USE_BOTH));
        sort($differs);
        $this->assertSame(['expected_points', 'primary'], $differs);
    }

    // ---------------------------------------------------------------- the start

    public function test_start_is_refused_until_server_arms_are_on_then_runs_14_days_and_serves_169_hours(): void
    {
        $id = $this->createDraft();

        $this->assertSame(
            'Experiments in mail, push and server code are switched off. Set the config key ux_server_arms to 1, then start this one again.',
            $this->refusal(fn () => $this->service->start($id))
        );

        $this->switchServerArmsOn();
        $started = $this->service->start($id);

        $this->assertSame('running', $started->status);
        $this->assertSame('2026-10-20 11:30:00', $started->started_at);
        $this->assertSame('2026-11-03 11:30:00', $started->ends_at, '336 hours');
        $design = json_decode($started->design, true);
        $this->assertSame(168, $design['intake_hours']);
        $this->assertSame(336, $design['max_hours']);
        $this->assertSame([], $design['touches'], 'a source experiment watches no funnel');

        $published = json_decode((string) DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->value('value'), true);
        $this->assertEquals(
            [self::NAME => ['arms' => ['control' => [0, 49], 'ask' => [50, 99]], 'until' => Carbon::parse('2026-10-27 12:30:00', 'UTC')->getTimestamp()]],
            $published,
            'served until 169 hours after the start, with no salt'
        );
    }

    public function test_it_has_its_own_slot_and_a_web_test_on_the_reply_funnel_may_run_beside_it(): void
    {
        $this->startTheExperiment();

        // A web experiment on the reply funnel runs beside it.
        $web = $this->service->create([
            'flow' => 'reply', 'name' => 'reply-web', 'title' => 'A reply form test', 'hypothesis' => 'A change.',
            'change' => 'Something else.', 'expected' => 'More replies.', 'design' => ['touches' => ['reply']],
        ]);
        $this->assertSame('running', $this->service->start($web)->status);

        // A second experiment in the same slot is refused.
        $second = $this->service->create([
            'flow' => 'reply', 'name' => 'confirm-reply-two', 'title' => 'A second test', 'hypothesis' => 'A change.',
            'change' => 'Something else.', 'expected' => 'More handovers.',
            'design' => json_decode(self::DESIGN_NO_WASTED_REPLY, true),
        ]);
        $this->assertSame(
            'Another experiment, confirm-reply, is already running for server:confirm-reply. Only one change at a time can run there, so the results can be told apart.',
            $this->refusal(fn () => $this->service->start($second))
        );
    }

    public function test_without_its_slot_it_takes_the_reply_funnel_and_waits_for_a_web_test_on_it(): void
    {
        // The one key that puts it back on the funnel is the slot.
        $design = json_decode(self::DESIGN_NO_WASTED_REPLY, true);
        unset($design['slot']);
        $this->switchServerArmsOn();
        $web = $this->service->create([
            'flow' => 'reply', 'name' => 'reply-web', 'title' => 'A reply form test', 'hypothesis' => 'A change.',
            'change' => 'Something else.', 'expected' => 'More replies.', 'design' => ['touches' => ['reply']],
        ]);
        $this->service->start($web);
        $server = $this->service->create([
            'flow' => 'reply', 'name' => 'confirm-reply', 'title' => 'Ask first', 'hypothesis' => 'A change.',
            'change' => 'A question.', 'expected' => 'Fewer wasted replies.', 'design' => $design,
        ]);

        $this->assertSame(
            'Experiment reply-web is already running on the funnel "Replying to a post". Only one change at a time can run on a funnel, so the results can be told apart.',
            $this->refusal(fn () => $this->service->start($server))
        );
    }

    public function test_a_start_needs_no_php_switch_list_entry_and_the_list_has_none(): void
    {
        $names = array_map(fn ($e) => is_array($e) ? ($e['name'] ?? null) : null, (array) config('ux_switches'));
        $this->assertNotContains(self::NAME, $names, 'no batch code reads this arm: the Go API does, and its list is ux.ServerSwitches');

        $this->assertSame('running', $this->startTheExperiment()->status, 'start checks the batch list only for mail and push');
    }

    // ---------------------------------------------------------------- real rows, counted

    public function test_both_arms_reach_real_rows_and_each_replier_is_counted_once(): void
    {
        $exp = $this->startTheExperiment('handover');
        $ranges = ['control' => [0, 49], 'ask' => [50, 99]];

        $members = [];
        for ($i = 0; $i < 80 && count($members) < 2; $i++) {
            $user = $this->createTestUser();
            $arm = ExperimentBucket::armIn($user->id, self::NAME, $ranges);
            $this->assertContains($arm, ['control', 'ask'], 'the arms cover every bucket');
            $members[$arm] ??= $user;
        }
        $this->assertCount(2, $members, 'a replier in each arm within 80 tries');

        $poster = $this->createTestUser();
        $at = '2026-10-20 12:00:00';
        $replies = [];
        foreach (['control', 'ask'] as $arm) {
            $post = $this->createTestMessage($poster, $this->group());
            $room = $this->createTestChatRoom($members[$arm], $poster);
            $reply = $this->createTestChatMessage($room, $members[$arm], ['type' => 'Interested', 'refmsgid' => $post->id, 'date' => $at, 'platform' => 1]);
            DB::table('ux_experiment_exposures')->insert([
                'experiment' => self::NAME, 'arm' => $arm, 'userid' => $members[$arm]->id, 'subject_type' => 'reply', 'subject_id' => $reply->id,
                'stratum' => 'damaged', 'exposed_at' => $at, 'detail' => null,
            ]);
            $replies[$arm] = [$post, $reply];
        }
        // One handover each way: the control replier is promised the item, the asked replier is not.
        DB::table('messages_promises')->insert(['msgid' => $replies['control'][0]->id, 'userid' => $members['control']->id, 'promisedat' => '2026-10-20 14:00:00']);
        // The asked replier replied again to a second post; it is a second row, and it is not counted again.
        $again = $this->createTestMessage($poster, $this->group());
        $second = $this->createTestChatMessage($this->createTestChatRoom($members['ask'], $this->createTestUser()), $members['ask'], ['type' => 'Interested', 'refmsgid' => $again->id, 'date' => '2026-10-21 12:00:00', 'platform' => 1]);
        DB::table('ux_experiment_exposures')->insert([
            'experiment' => self::NAME, 'arm' => 'ask', 'userid' => $members['ask']->id, 'subject_type' => 'reply', 'subject_id' => $second->id,
            'stratum' => 'car', 'exposed_at' => '2026-10-21 12:00:00', 'detail' => null,
        ]);
        DB::table('messages_promises')->insert(['msgid' => $again->id, 'userid' => $members['ask']->id, 'promisedat' => '2026-10-21 13:00:00']);

        // A week and a day later, the first replies have had their window.
        Carbon::setTestNow('2026-10-28 12:00:00');
        $lines = $this->service->tick();

        $result = json_decode($this->service->find($exp->id)->result, true);
        $this->assertSame(1, $result['arms']['control']['starts'], implode(' | ', $lines));
        $this->assertSame(1, $result['arms']['control']['completes']);
        $this->assertSame(1, $result['arms']['ask']['starts'], 'counted once, on the first reply');
        $this->assertSame(0, $result['arms']['ask']['completes'], 'the first reply was not handed over, and the second does not count');
        $all = $result['detail']['reasons']['all'];
        $this->assertSame(1, $all['control']['replies']);
        $this->assertSame(1, $all['ask']['replies']);
        $this->assertSame(1, $all['control']['handed_over']);
        $this->assertSame(['damaged'], array_values(array_diff(array_keys($result['detail']['reasons']), ['all'])), 'the second reply\'s reason is not counted');
        $this->assertSame([], $this->lokiQueries, 'nothing was read from the logs');
        $this->assertSame('repliers', $result['words']['who']);
    }

    /** @return array<string, array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int, 6: bool, 7: string}> */
    public static function guardrailCases(): array
    {
        // [script, control handed, control dropped, ask handed, ask dropped, counted on each, reverts, a phrase of the decision]
        return [
            'handover: 40 points more lost does not trip a 50 point guard' => ['handover', 0, 0, 0, 120, 300, false, ''],
            'handover: 60 points more lost trips it' => ['handover', 0, 0, 0, 180, 300, true, 'worse by more than 50 points'],
            'no wasted reply: one point fewer handovers does not trip a 2 point guard' => ['no_wasted_reply', 120, 0, 117, 0, 300, false, ''],
            'no wasted reply: 20 points fewer handovers trips it' => ['no_wasted_reply', 120, 0, 60, 0, 300, true, 'worse by more than 2 points'],
            'no wasted reply: fewer than 300 counted waits' => ['no_wasted_reply', 120, 0, 56, 0, 299, false, ''],
        ];
    }

    #[DataProvider('guardrailCases')]
    public function test_the_guardrail_reverts_an_early_run_only_past_its_margin_and_300_counted(string $script, int $controlHanded, int $controlDropped, int $askHanded, int $askDropped, int $counted, bool $reverts, string $phrase): void
    {
        $exp = $this->startTheExperiment($script);
        $this->seedCohort('control', $counted, $controlHanded, $controlDropped);
        $this->seedCohort('ask', $counted, $askHanded, $askDropped);

        Carbon::setTestNow('2026-10-28 12:00:00');
        $lines = $this->service->tick();

        $after = $this->service->find($exp->id);
        if ($reverts) {
            $this->assertSame('reverted', $after->status, implode(' | ', $lines));
            $this->assertStringContainsString('Reverted early', $after->decision);
            $this->assertStringContainsString($phrase, $after->decision);
        } else {
            $this->assertSame('running', $after->status, $after->decision.' '.implode(' | ', $lines));
        }
    }

    // ---------------------------------------------------------------- the size of a run

    public function test_the_power_gate_uses_volume(): void
    {
        config()->set('freegle.ux.experiments.min_power', 0.30);
        $poster = $this->createTestUser();
        $handed = null;
        // Four repliers to damaged offers in the week that ended a week ago, one of them promised the item: too few to read a change of 10 points.
        for ($i = 0; $i < 4; $i++) {
            $replier = $this->createTestUser();
            $post = $this->createTestMessage($poster, $this->group(), ['type' => 'Offer']);
            DB::table('messages_eee')->insert([
                'msgid' => $post->id, 'item_condition' => 'damaged', 'size_bucket' => 'small', 'weight_bucket' => '1_5kg',
                'model' => 'test-model', 'prompt_version' => 'v1', 'classified_at' => '2026-10-05 12:00:00',
            ]);
            $this->createTestChatMessage($this->createTestChatRoom($replier, $poster), $replier, [
                'type' => 'Interested', 'refmsgid' => $post->id, 'date' => '2026-10-08 12:00:00', 'platform' => 1,
            ]);
            $handed ??= [$post, $replier];
        }
        DB::table('messages_promises')->insert(['msgid' => $handed[0]->id, 'userid' => $handed[1]->id, 'promisedat' => '2026-10-08 18:00:00']);
        $id = $this->createDraft();
        $this->switchServerArmsOn();

        $message = $this->refusal(fn () => $this->service->start($id));

        $this->assertStringContainsString('A run of this experiment (about 2 repliers on each arm) would adopt a change of 10 points only', $message);
        $this->assertStringContainsString('set design.expected_points to the change you really expect', $message);
        $this->assertStringNotContainsString('A day of the funnel', $message);
        $this->assertSame('draft', $this->service->find($id)->status);
    }

    // ---------------------------------------------------------------- the way back

    public function test_stop_takes_the_entry_off_the_config_key(): void
    {
        $this->startTheExperiment();
        $this->assertArrayHasKey(self::NAME, json_decode((string) DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->value('value'), true));

        $this->artisan('ux:experiment', ['action' => 'stop', 'name' => self::NAME, '--reason' => 'test'])->assertExitCode(0);

        $this->assertSame('{}', DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->value('value'), 'the entry leaves the key at once, so every replier is control within a minute');
        $this->assertSame('stopped', $this->service->find(self::NAME)->status);
    }
}
