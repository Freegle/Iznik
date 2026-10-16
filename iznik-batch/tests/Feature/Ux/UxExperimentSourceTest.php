<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxArm;
use App\Services\Ux\UxExperimentRefused;
use App\Services\Ux\UxExperimentService;
use App\Support\ExperimentBucket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesUxLoki;
use Tests\Support\FakeUxMetricSource;
use Tests\TestCase;

/**
 * The engine with an experiment that is decided on the server: mail, push or server code, counted
 * from the database through a metric source instead of from flow events in Loki. A fake source stands
 * in for the real ones (EmailClickSourceTest and ActiveAfterSourceTest test those over real rows), so
 * what is tested here is the engine: the design keys, the refusals, the run length, the slot, the
 * statistics over a source's counts, its guardrails with their own margins, the words, the fixed
 * split and the config key. docs/developers/reference/ux-experiments.md.
 */
class UxExperimentSourceTest extends TestCase
{
    use FakesUxLoki;

    private UxExperimentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->fakeLoki();
        FakeUxMetricSource::reset();
        foreach (['ux_experiment_reads', 'ux_experiments', 'ux_flow_outcome_daily'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('config')->whereIn('key', [UxExperimentService::CONFIG_KEY, UxArm::SWITCH_KEY])->delete();
        $this->setSwitch('1');
        config()->set('freegle.ux.sources.fake', FakeUxMetricSource::class);
        config()->set('ux_switches', []);
        config()->set('freegle.ux.experiments.min_starts_per_arm', 50);
        $this->service = app(UxExperimentService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        FakeUxMetricSource::reset();
        parent::tearDown();
    }

    private function setSwitch(?string $value): void
    {
        if ($value === null) {
            DB::table('config')->where('key', UxArm::SWITCH_KEY)->delete();

            return;
        }
        DB::table('config')->upsert([['key' => UxArm::SWITCH_KEY, 'value' => $value]], ['key'], ['value']);
    }

    /** A design that is counted by the fake source, in server code (which needs no entry in a switch list). */
    private function design(array $over = []): array
    {
        return $over + ['channel' => 'server', 'type' => 'confirm', 'source' => 'fake', 'window_hours' => 24, 'expected_points' => 1];
    }

    private function attrs(array $design, array $over = []): array
    {
        return $over + [
            'flow' => 'reply', 'name' => 'srv-test', 'title' => 'Ask repliers to confirm',
            'hypothesis' => 'Repliers who never meant to take the item waste the poster\'s time.',
            'change' => 'A question is asked of the replier before the poster sees the reply.',
            'expected' => 'More handovers.', 'design' => $design,
        ];
    }

    private function started(array $design = [], array $over = []): int
    {
        $id = $this->service->create($this->attrs($this->design($design), $over));
        $this->service->start($id);

        return $id;
    }

    private function designOf(int $id): array
    {
        return json_decode($this->service->find($id)->design, true);
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

    private function configEntry(string $name = 'srv-test'): array
    {
        return json_decode(DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->value('value'), true)[$name];
    }

    // ---------------------------------------------------------------- the design keys

    public function test_a_full_design_is_kept_and_a_channel_fixes_the_split(): void
    {
        $id = $this->service->create($this->attrs([
            'channel' => 'email', 'type' => 'UnifiedDigestDaily', 'source' => 'fake', 'window_hours' => 24, 'intake_hours' => 24,
            'max_hours' => 48, 'salt' => 'r2', 'slot' => 'email:digest', 'expected_points' => 0.5, 'touches' => [],
            'copy' => ['control' => ' Your daily digest ', 'b' => 'Items near you'], 'anything' => 'else',
        ]));

        $this->assertEquals([
            'touches' => [], 'expected_points' => 0.5, 'channel' => 'email', 'type' => 'UnifiedDigestDaily', 'source' => 'fake',
            'window_hours' => 24, 'intake_hours' => 24, 'max_hours' => 48, 'fixed_split' => true, 'salt' => 'r2', 'slot' => 'email:digest',
            'copy' => ['control' => 'Your daily digest', 'b' => 'Items near you'],
        ], $this->designOf($id));
    }

    public function test_a_web_experiment_keeps_the_design_it_always_had(): void
    {
        $id = $this->service->create($this->attrs(['touches' => ['reply'], 'expected_points' => 3.5, 'channel' => 'web']));

        $this->assertEquals(['touches' => ['reply'], 'expected_points' => 3.5], $this->designOf($id));
    }

    public function test_a_web_experiment_may_ask_for_a_fixed_split_and_a_channel_may_not_refuse_one(): void
    {
        $web = $this->service->create($this->attrs(['fixed_split' => true], ['name' => 'web-fixed']));
        $this->assertTrue($this->designOf($web)['fixed_split']);

        $channel = $this->service->create($this->attrs($this->design(['fixed_split' => false]), ['name' => 'srv-fixed']));
        $this->assertTrue($this->designOf($channel)['fixed_split'], 'a channel other than web is always a fixed split');
    }

    public function test_the_design_may_be_json(): void
    {
        $id = $this->service->create(array_merge($this->attrs([]), ['name' => 'srv-json', 'design' => json_encode($this->design())]));

        $this->assertSame('fake', $this->designOf($id)['source']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badDesigns(): array
    {
        $ok = ['channel' => 'server', 'source' => 'fake', 'window_hours' => 24];

        return [
            'an unknown channel' => [['channel' => 'sms'], 'design.channel must be email, push or server'],
            'a type with a space' => [$ok + ['type' => 'bad type'], 'design.type must be 1 to 48'],
            'a type of 49 characters' => [$ok + ['type' => str_repeat('t', 49)], 'design.type must be 1 to 48'],
            'a source that does not exist' => [['channel' => 'server', 'source' => 'nope', 'window_hours' => 24], 'design.source is not a metric source that exists'],
            'a source for a web experiment' => [['source' => 'fake', 'window_hours' => 24], 'design.source needs a channel'],
            'a source with no window' => [['channel' => 'server', 'source' => 'fake'], 'design.window_hours is needed with design.source'],
            'a window of 0' => [self::withWindow(0), 'design.window_hours must be a whole number of hours, 1 or more'],
            'a window with a fraction' => [['window_hours' => '1.5'] + $ok, 'design.window_hours must be a whole number of hours'],
            'a window that is text' => [['window_hours' => 'a day'] + $ok, 'design.window_hours must be a whole number of hours'],
            'an intake of 0' => [$ok + ['intake_hours' => 0], 'design.intake_hours must be a whole number of hours'],
            'an intake longer than the run' => [$ok + ['intake_hours' => 50, 'max_hours' => 40], 'design.intake_hours must not be longer than design.max_hours'],
            'hours with no source' => [['max_hours' => 24], 'design.max_hours is only for an experiment with a design.source'],
            'a window with no source' => [['channel' => 'email', 'window_hours' => 24], 'design.window_hours is only for an experiment with a design.source'],
            'a salt with a space' => [['salt' => 'r 2'], 'design.salt must be 1 to 16'],
            'a salt of 17 characters' => [['salt' => str_repeat('s', 17)], 'design.salt must be 1 to 16'],
            'a salt that is a number' => [['salt' => 5], 'design.salt must be 1 to 16'],
            'a slot with a space' => [['slot' => 'a slot'], 'design.slot must be 1 to 48'],
            'a slot of 49 characters' => [['slot' => str_repeat('s', 49)], 'design.slot must be 1 to 48'],
            'copy that is text' => [['copy' => 'hello'], 'design.copy must give the text each arm shows'],
            'copy that is empty' => [['copy' => []], 'design.copy must give the text each arm shows'],
            'copy for an arm with a space' => [['copy' => ['bad arm' => 'hello']], 'design.copy must give the text each arm shows'],
            'copy with no text' => [['copy' => ['b' => '  ']], 'design.copy must give the text each arm shows'],
            'copy of 241 characters' => [['copy' => ['b' => str_repeat('x', 241)]], 'up to 240 characters'],
        ];
    }

    /** @return array<string, mixed> */
    private static function withWindow(int $hours): array
    {
        return ['channel' => 'server', 'source' => 'fake', 'window_hours' => $hours];
    }

    /** @param array<string, mixed> $design */
    #[DataProvider('badDesigns')]
    public function test_a_design_that_is_wrong_is_refused_in_plain_words(array $design, string $message): void
    {
        try {
            $this->service->create($this->attrs($design));
            $this->fail('Expected the design to be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_copy_of_exactly_240_characters_and_a_type_of_48_are_kept(): void
    {
        $id = $this->service->create($this->attrs($this->design(['type' => str_repeat('t', 48), 'copy' => ['b' => str_repeat('x', 240)]])));

        $this->assertSame(240, mb_strlen($this->designOf($id)['copy']['b']));
    }

    // ---------------------------------------------------------------- start: refusals

    public function test_server_arms_switched_off_refuses_the_start(): void
    {
        foreach ([null, '0', 'true', ''] as $i => $value) {
            $this->setSwitch($value);
            $id = $this->service->create($this->attrs($this->design(), ['name' => "srv-off-$i"]));

            $this->assertSame(
                'Experiments in mail, push and server code are switched off. Set the config key ux_server_arms to 1, then start this one again.',
                $this->refusal(fn () => $this->service->start($id))
            );
        }
    }

    public function test_what_the_tab_prints_for_a_refusal_avoids_the_words_of_the_code(): void
    {
        // The tab prints a refusal after "That was not done:". Staff read "version", never "arm", and "slot"
        // already means the cap on running experiments there.
        $this->setSwitch(null);
        $off = $this->refusal(fn () => $this->service->start($this->service->create($this->attrs($this->design(), ['name' => 'words-off']))));

        config()->set('ux_switches', [$this->entry('words-list', 'push', 'daily_new_posts', ['arms' => ['control', 'other']])]);
        $this->setSwitch('1');
        $list = $this->refusal(fn () => $this->service->start($this->service->create($this->attrs($this->design(['channel' => 'push', 'type' => 'daily_new_posts']), ['name' => 'words-list']))));

        foreach ([$off, $list] as $message) {
            $this->assertDoesNotMatchRegularExpression('/\barms?\b/i', $message, $message);
            $this->assertDoesNotMatchRegularExpression('/\bslot\b/i', $message, $message);
        }
    }

    public function test_a_web_experiment_does_not_need_server_arms(): void
    {
        $this->setSwitch(null);
        $id = $this->service->create($this->attrs(['touches' => ['reply']], ['name' => 'web-no-switch']));

        $this->assertSame('running', $this->service->start($id)->status);
    }

    public function test_an_email_experiment_is_refused_when_email_tracking_is_off(): void
    {
        config()->set('ux_switches', [$this->entry('digest-subject', 'email', 'UnifiedDigestDaily')]);
        config()->set('freegle.mail.tracking_enabled', false);
        $id = $this->service->create($this->attrs($this->design(['channel' => 'email', 'type' => 'UnifiedDigestDaily']), ['name' => 'digest-subject']));

        $this->assertStringContainsString('Email tracking is switched off', $this->refusal(fn () => $this->service->start($id)));

        config()->set('freegle.mail.tracking_enabled', true);
        $this->assertSame('running', $this->service->start($id)->status);
    }

    /** @return array<string, mixed> */
    private function entry(string $name, string $channel, string $type, array $over = []): array
    {
        return $over + [
            'name' => $name, 'opened' => '2026-10-20', 'pr' => 'https://github.com/Freegle/Iznik/pull/1', 'keep_until' => null,
            'channel' => $channel, 'type' => $type, 'varies' => 'subject', 'arms' => ['control', 'b'],
            'metric' => ['source' => 'fake', 'window_hours' => 24],
        ];
    }

    public function test_an_email_or_push_experiment_whose_code_is_not_deployed_is_refused(): void
    {
        foreach (['email', 'push'] as $channel) {
            $id = $this->service->create($this->attrs($this->design(['channel' => $channel, 'type' => 'kind']), ['name' => "not-deployed-$channel"]));

            $this->assertStringContainsString("The code for not-deployed-$channel is not deployed yet", $this->refusal(fn () => $this->service->start($id)));
        }
    }

    public function test_a_switch_list_entry_that_does_not_match_the_experiment_refuses_the_start(): void
    {
        $id = $this->service->create($this->attrs($this->design(['channel' => 'push', 'type' => 'daily_new_posts']), ['name' => 'push-mismatch']));

        config()->set('ux_switches', [$this->entry('push-mismatch', 'push', 'daily_new_posts', ['arms' => ['control', 'other']])]);
        $this->assertStringContainsString('The versions in the switch list for push-mismatch (control, other) are not the versions of this experiment (b, control)', $this->refusal(fn () => $this->service->start($id)));

        config()->set('ux_switches', [$this->entry('push-mismatch', 'push', 'daily_new_posts', ['metric' => ['source' => 'email_click', 'window_hours' => 24]])]);
        $this->assertStringContainsString('counted by "email_click", but this experiment is counted by "fake"', $this->refusal(fn () => $this->service->start($id)));

        config()->set('ux_switches', [$this->entry('push-mismatch', 'push', 'daily_new_posts')]);
        $this->assertSame('running', $this->service->start($id)->status);
    }

    public function test_the_order_of_arms_in_the_switch_list_does_not_matter(): void
    {
        config()->set('ux_switches', [$this->entry('push-order', 'push', 'daily_new_posts', ['arms' => ['b', 'control']])]);
        $id = $this->service->create($this->attrs($this->design(['channel' => 'push', 'type' => 'daily_new_posts']), ['name' => 'push-order']));

        $this->assertSame('running', $this->service->start($id)->status);
    }

    public function test_a_server_experiment_needs_no_entry_in_the_batch_switch_list(): void
    {
        $this->assertSame('running', $this->service->start($this->service->create($this->attrs($this->design())))->status);
    }

    public function test_every_refusal_is_the_kind_the_queue_does_not_retry(): void
    {
        $this->setSwitch(null);
        $id = $this->service->create($this->attrs($this->design()));

        $this->expectException(UxExperimentRefused::class);
        $this->service->start($id);
    }

    // ---------------------------------------------------------------- start: run length

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: int, 3: int}> */
    public static function runs(): array
    {
        return [
            'server: intake a week, window a day' => ['server', ['window_hours' => 24], 168, 192],
            'server: a window a week long' => ['server', ['window_hours' => 168], 168, 336],
            'email: intake a day, window a day' => ['email', ['window_hours' => 24], 24, 48],
            'email: a window a week long, run 168 as asked' => ['email', ['window_hours' => 168, 'intake_hours' => 0, 'max_hours' => 168], 0, 168],
            'push: intake a day, window a day' => ['push', ['window_hours' => 24], 24, 48],
            'push: a shorter intake' => ['push', ['window_hours' => 12, 'intake_hours' => 6], 6, 18],
            'email: the run set by hand' => ['email', ['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 100], 12, 100],
        ];
    }

    /** @param array<string, mixed> $over */
    #[DataProvider('runs')]
    public function test_the_run_is_the_intake_and_the_window_unless_the_design_says(string $channel, array $over, int $intake, int $max): void
    {
        if (($over['intake_hours'] ?? null) === 0) {
            unset($over['intake_hours']);
            $intake = 24;
        }
        config()->set('ux_switches', [$this->entry('run-test', $channel, 'kind')]);
        $id = $this->service->create($this->attrs($this->design(['channel' => $channel, 'type' => 'kind'] + $over), ['name' => 'run-test']));

        $exp = $this->service->start($id);
        $design = $this->designOf($id);

        $this->assertSame($intake, $design['intake_hours'], 'the resolved intake is stored');
        $this->assertSame($max, $design['max_hours'], 'and the resolved run');
        $this->assertSame(Carbon::now('UTC')->addHours($max)->toDateTimeString(), $exp->ends_at);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function runsTooLong(): array
    {
        return [
            'push: 49 hours' => ['push', ['window_hours' => 25]],
            'push: a run of 72 set by hand' => ['push', ['window_hours' => 24, 'max_hours' => 72]],
            'email: 169 hours' => ['email', ['window_hours' => 24, 'intake_hours' => 24, 'max_hours' => 169]],
            'email: a window of a week and the default intake' => ['email', ['window_hours' => 168]],
            'server: 337 hours' => ['server', ['window_hours' => 169]],
        ];
    }

    /** @param array<string, mixed> $over */
    #[DataProvider('runsTooLong')]
    public function test_a_run_over_the_ceiling_of_its_channel_is_refused(string $channel, array $over): void
    {
        config()->set('ux_switches', [$this->entry('long-test', $channel, 'kind')]);
        $id = $this->service->create($this->attrs($this->design(['channel' => $channel, 'type' => 'kind'] + $over), ['name' => 'long-test']));

        $message = $this->refusal(fn () => $this->service->start($id));

        $this->assertStringContainsString(ucfirst($channel).' experiments run for at most', $message);
        $this->assertSame('draft', $this->service->find($id)->status);
    }

    public function test_the_refusal_of_a_run_over_the_ceiling_reads_as_plain_english_for_every_channel(): void
    {
        // "A email" was the wording of an earlier version, and no channel has an article in this one.
        $expected = [
            'email' => 'Email experiments run for at most 168 hours, and this one would run for 192. Shorten the window or the time members are reached.',
            'push' => 'Push experiments run for at most 48 hours, and this one would run for 72. Shorten the window or the time members are reached.',
            'server' => 'Server experiments run for at most 336 hours, and this one would run for 360. Shorten the window or the time members are reached.',
        ];
        $max = ['email' => 192, 'push' => 72, 'server' => 360];
        foreach ($expected as $channel => $text) {
            config()->set('ux_switches', [$this->entry("ceiling-$channel", $channel, 'kind')]);
            $id = $this->service->create($this->attrs($this->design(['channel' => $channel, 'type' => 'kind', 'window_hours' => 24, 'intake_hours' => 24, 'max_hours' => $max[$channel]]), ['name' => "ceiling-$channel"]));

            $this->assertSame($text, $this->refusal(fn () => $this->service->start($id)));
        }
    }

    public function test_an_intake_longer_than_the_run_is_refused_at_start_when_one_of_them_is_the_default(): void
    {
        // The email default intake is 24 hours and the run is set to 12.
        config()->set('ux_switches', [$this->entry('intake-test', 'email', 'kind')]);
        $id = $this->service->create($this->attrs($this->design(['channel' => 'email', 'type' => 'kind', 'max_hours' => 12]), ['name' => 'intake-test']));

        $this->assertStringContainsString('longer than the whole run of 12 hours', $this->refusal(fn () => $this->service->start($id)));
    }

    public function test_a_web_experiment_keeps_the_global_run_of_24_hours(): void
    {
        $id = $this->service->create($this->attrs(['touches' => ['reply']], ['name' => 'web-run']));

        $exp = $this->service->start($id);

        $this->assertSame('2026-10-21 10:00:00', $exp->ends_at);
        $this->assertSame(Carbon::parse('2026-10-21 11:00:00', 'UTC')->getTimestamp(), $this->configEntry('web-run')['until']);
    }

    public function test_the_config_until_of_a_source_experiment_is_the_end_of_intake_plus_an_hour(): void
    {
        $this->started(['window_hours' => 24]); // server: intake 168, run 192

        $this->assertSame(Carbon::parse('2026-10-20 10:00:00', 'UTC')->addHours(168)->getTimestamp() + 3600, $this->configEntry()['until']);
        $this->assertSame('2026-10-28 10:00:00', $this->service->find('srv-test')->ends_at);
    }

    public function test_a_channel_experiment_with_no_source_keeps_the_run_of_the_web(): void
    {
        $id = $this->service->create($this->attrs(['channel' => 'email', 'type' => 'kind'], ['name' => 'no-source']));

        $exp = $this->service->start($id);

        $this->assertSame('2026-10-21 10:00:00', $exp->ends_at);
        $this->assertSame(Carbon::parse('2026-10-21 11:00:00', 'UTC')->getTimestamp(), $this->configEntry('no-source')['until']);
    }

    public function test_the_start_of_a_source_experiment_stores_no_touches_and_no_funnel_figure(): void
    {
        $id = $this->started();

        $this->assertSame([], $this->designOf($id)['touches']);
        $this->assertSame([], json_decode($this->service->find($id)->result, true), 'no mde_points: a day of the funnel says nothing about it');
    }

    // ---------------------------------------------------------------- start: the slot

    public function test_one_running_experiment_per_slot_and_a_slot_is_a_channel_and_a_type(): void
    {
        config()->set('ux_switches', [$this->entry('digest-a', 'email', 'UnifiedDigestDaily'), $this->entry('digest-b', 'email', 'UnifiedDigestDaily')]);
        $a = $this->service->create($this->attrs($this->design(['channel' => 'email', 'type' => 'UnifiedDigestDaily']), ['name' => 'digest-a']));
        $b = $this->service->create($this->attrs($this->design(['channel' => 'email', 'type' => 'UnifiedDigestDaily']), ['name' => 'digest-b']));
        $this->service->start($a);

        $this->assertSame(
            'Another email experiment, digest-a, is already running for UnifiedDigestDaily. Only one change at a time can run there, so the results can be told apart.',
            $this->refusal(fn () => $this->service->start($b))
        );
    }

    public function test_a_push_or_server_slot_names_its_channel_and_its_type(): void
    {
        config()->set('ux_switches', [$this->entry('push-a', 'push', 'daily_new_posts'), $this->entry('push-b', 'push', 'daily_new_posts')]);
        $this->service->start($this->service->create($this->attrs($this->design(['channel' => 'push', 'type' => 'daily_new_posts']), ['name' => 'push-a'])));
        $this->service->start($this->service->create($this->attrs($this->design(['channel' => 'server', 'type' => 'confirm']), ['name' => 'server-a'])));

        $push = $this->service->create($this->attrs($this->design(['channel' => 'push', 'type' => 'daily_new_posts']), ['name' => 'push-b']));
        $server = $this->service->create($this->attrs($this->design(['channel' => 'server', 'type' => 'confirm']), ['name' => 'server-b']));

        $this->assertStringStartsWith('Another push experiment, push-a, is already running for daily_new_posts.', $this->refusal(fn () => $this->service->start($push)));
        $this->assertStringStartsWith('Another server experiment, server-a, is already running for confirm.', $this->refusal(fn () => $this->service->start($server)));
    }

    public function test_a_digest_test_and_a_reply_funnel_test_run_together(): void
    {
        config()->set('ux_switches', [$this->entry('digest-a', 'email', 'UnifiedDigestDaily')]);
        $digest = $this->service->create($this->attrs($this->design(['channel' => 'email', 'type' => 'UnifiedDigestDaily']), ['name' => 'digest-a']));
        $web = $this->service->create($this->attrs(['touches' => ['reply']], ['name' => 'reply-web']));

        $this->service->start($digest);
        $this->assertSame('running', $this->service->start($web)->status);
    }

    public function test_a_slot_that_is_a_funnel_keeps_the_wording_staff_know(): void
    {
        $this->service->start($this->service->create($this->attrs(['touches' => ['reply']], ['name' => 'reply-web'])));
        $other = $this->service->create($this->attrs(['touches' => ['reply']], ['name' => 'reply-web-2']));

        $this->assertStringContainsString('Experiment reply-web is already running on the funnel "Replying to a post". Only one change at a time can run on a funnel', $this->refusal(fn () => $this->service->start($other)));
    }

    public function test_an_experiment_with_no_type_or_slot_holds_its_funnel_whatever_its_channel(): void
    {
        $this->service->start($this->service->create($this->attrs(['touches' => ['reply']], ['name' => 'reply-web'])));
        $server = $this->service->create($this->attrs($this->design(['type' => null]), ['name' => 'reply-server']));

        $this->assertStringContainsString('already running on the funnel "Replying to a post"', $this->refusal(fn () => $this->service->start($server)));
    }

    public function test_a_slot_set_in_the_design_is_the_slot(): void
    {
        $this->service->start($this->service->create($this->attrs($this->design(['slot' => 'shared:thing']), ['name' => 'shared-a'])));
        $b = $this->service->create($this->attrs($this->design(['type' => 'other', 'slot' => 'shared:thing']), ['name' => 'shared-b']));

        $this->assertSame(
            'Another experiment, shared-a, is already running for shared:thing. Only one change at a time can run there, so the results can be told apart.',
            $this->refusal(fn () => $this->service->start($b))
        );
    }

    public function test_a_slot_of_zero_is_a_slot(): void
    {
        // "0" is a natural first label, and PHP counts the string "0" as empty.
        $this->service->start($this->service->create($this->attrs($this->design(['slot' => '0']), ['name' => 'zero-a'])));
        $b = $this->service->create($this->attrs($this->design(['type' => 'other', 'slot' => '0']), ['name' => 'zero-b']));

        $this->assertStringStartsWith('Another experiment, zero-a, is already running for 0.', $this->refusal(fn () => $this->service->start($b)));
    }

    public function test_a_type_of_zero_makes_the_slot_of_its_channel(): void
    {
        $this->service->start($this->service->create($this->attrs($this->design(['type' => '0']), ['name' => 'type-zero-a'])));
        $b = $this->service->create($this->attrs($this->design(['type' => '0']), ['name' => 'type-zero-b']));

        $this->assertStringStartsWith('Another server experiment, type-zero-a, is already running for 0.', $this->refusal(fn () => $this->service->start($b)));
    }

    public function test_a_stopped_experiment_frees_its_slot(): void
    {
        $a = $this->started([], ['name' => 'slot-a']);
        $this->service->stop($a);

        $this->assertSame('running', $this->service->start($this->service->create($this->attrs($this->design(), ['name' => 'slot-b'])))->status);
    }

    public function test_the_cap_of_running_experiments_is_shared_across_channels(): void
    {
        config()->set('freegle.ux.experiments.max_concurrent', 1);
        $this->started([], ['name' => 'slot-a']);
        $b = $this->service->create($this->attrs($this->design(['type' => 'other']), ['name' => 'slot-b']));

        $this->assertStringContainsString('that is the cap', $this->refusal(fn () => $this->service->start($b)));
    }

    // ---------------------------------------------------------------- start: the power check

    public function test_a_run_too_small_to_read_is_refused_with_the_run_and_the_noun_not_a_day_of_the_funnel(): void
    {
        FakeUxMetricSource::$volume = ['per_arm' => 5, 'rate' => 0.03];
        $id = $this->service->create($this->attrs($this->design(['expected_points' => 5])));

        $message = $this->refusal(fn () => $this->service->start($id));

        $this->assertStringContainsString('A run of this experiment (about 5 members on each arm) would adopt a change of 5 points only', $message);
        $this->assertStringContainsString('set design.expected_points to the change you really expect', $message);
        $this->assertStringNotContainsString('A day of the funnel', $message);
    }

    public function test_a_run_big_enough_to_read_starts_and_stores_its_power(): void
    {
        FakeUxMetricSource::$volume = ['per_arm' => 50000, 'rate' => 0.03];
        $id = $this->started(['expected_points' => 1]);

        $this->assertGreaterThan(0.3, $this->designOf($id)['power']);
    }

    public function test_a_source_with_no_volume_skips_the_power_check(): void
    {
        FakeUxMetricSource::$volume = null;

        $id = $this->started(['expected_points' => 50]);

        $this->assertArrayNotHasKey('power', $this->designOf($id));
    }

    // ---------------------------------------------------------------- reading

    /** Move to $hours after the start, as the hourly tick would. */
    private function at(float $hours): Carbon
    {
        $now = Carbon::parse('2026-10-20 10:00:00', 'UTC')->addMinutes((int) round($hours * 60));
        Carbon::setTestNow($now);

        return $now;
    }

    public function test_the_read_comes_from_the_source_and_makes_no_loki_call(): void
    {
        $id = $this->started();
        FakeUxMetricSource::counting(['control' => [300, 30], 'b' => [300, 60]]);

        $read = $this->service->read($this->service->find($id), $this->at(5));

        $this->assertSame(300, $read['arms']['b']['starts']);
        $this->assertSame(60, $read['arms']['b']['completes']);
        $this->assertGreaterThan(0.99, $read['primary']['b']['p_better']);
        $this->assertSame([], $this->lokiQueries, 'no Loki call');
        $this->assertSame(300, $read['window_minutes'], 'five hours since the start');
    }

    public function test_the_read_carries_the_words_and_the_detail(): void
    {
        $id = $this->started();
        FakeUxMetricSource::counting(['control' => [300, 30], 'b' => [300, 60]]);

        $read = $this->service->read($this->service->find($id), $this->at(5));

        $this->assertSame('members', $read['words']['who']);
        $this->assertSame('Counted', $read['words']['tab_started']);
        $this->assertSame(['control' => 310, 'b' => 310], $read['detail']['exposed']);
        $this->assertSame(['control' => 300, 'b' => 300], $read['detail']['counted']);
    }

    public function test_every_arm_is_in_the_read_even_when_the_source_counted_none(): void
    {
        $id = $this->started();
        FakeUxMetricSource::$counts = ['arms' => [], 'guards' => [], 'extra' => []];

        $read = $this->service->read($this->service->find($id), $this->at(2));

        $this->assertSame(0, $read['arms']['control']['starts']);
        $this->assertSame(0, $read['arms']['b']['completes']);
        $this->assertSame([], $read['guardrails']);
    }

    public function test_the_share_check_compares_the_members_counted_with_the_split_that_was_served(): void
    {
        $id = $this->started();

        FakeUxMetricSource::counting(['control' => [300, 30], 'b' => [300, 33]]);
        $even = $this->service->read($this->service->find($id), $this->at(5));
        $this->assertFalse($even['primary']['b']['share_off']);
        $this->assertSame(50.0, (float) $even['primary']['b']['expected_share']);
        $this->assertArrayNotHasKey('expected_starts', $even['arms']['b']);

        FakeUxMetricSource::counting(['control' => [500, 50], 'b' => [100, 12]]);
        $skewed = $this->service->read($this->service->find($id), $this->at(5));
        $this->assertTrue($skewed['primary']['b']['share_off'], 'an arm that holds 17 percent of the members counted when it was served 50');
    }

    public function test_a_guardrail_with_its_own_margin_reverts_early_at_300_counted_and_the_sentence_names_that_margin(): void
    {
        $id = $this->started();
        FakeUxMetricSource::$guardrails = ['unsubscribe' => ['label' => 'the share of members who unsubscribed', 'margin_points' => 0.3]];
        // Control 2 in 300 unsubscribed (0.7%), the change 14 in 300 (4.7%).
        FakeUxMetricSource::counting(['control' => [300, 30], 'b' => [300, 33]], ['unsubscribe' => ['control' => [2, 300], 'b' => [14, 300]]]);

        $lines = $this->service->tick($this->at(5));

        $exp = $this->service->find($id);
        $this->assertSame('reverted', $exp->status, implode(' | ', $lines));
        $this->assertStringContainsString('Reverted early: the share of members who unsubscribed got worse with the change', $exp->decision);
        $this->assertStringContainsString('worse by more than 0.3 points', $exp->decision);
        $this->assertStringNotContainsString('2 points', $exp->decision, 'the sentence reads the guardrail\'s margin, not the 2 point setting');
        $row = json_decode($exp->result, true)['guardrails'][0];
        $this->assertSame(0.3, $row['margin_points']);
        $this->assertSame('unsubscribe', $row['name']);
    }

    public function test_the_same_guardrail_does_not_revert_early_before_300_are_counted_on_both_arms(): void
    {
        $id = $this->started();
        FakeUxMetricSource::$guardrails = ['unsubscribe' => ['label' => 'the share of members who unsubscribed', 'margin_points' => 0.3]];
        FakeUxMetricSource::counting(['control' => [299, 30], 'b' => [300, 33]], ['unsubscribe' => ['control' => [2, 299], 'b' => [14, 300]]]);

        $this->service->tick($this->at(5));

        $this->assertSame('running', $this->service->find($id)->status);
    }

    public function test_a_guardrail_is_not_read_until_100_are_counted_across_both_arms(): void
    {
        $id = $this->started();
        FakeUxMetricSource::$guardrails = ['unsubscribe' => ['label' => 'the share of members who unsubscribed', 'margin_points' => 0.3]];
        FakeUxMetricSource::counting(['control' => [49, 5], 'b' => [50, 5]], ['unsubscribe' => ['control' => [0, 49], 'b' => [40, 50]]]);

        $read = $this->service->read($this->service->find($id), $this->at(5));

        $this->assertSame([], $read['guardrails'], '99 counted is below the floor of 100');
    }

    public function test_a_guardrail_blocks_an_adoption_at_the_end(): void
    {
        $id = $this->started(['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        FakeUxMetricSource::$guardrails = ['unsubscribe' => ['label' => 'the share of members who unsubscribed', 'margin_points' => 0.3]];
        FakeUxMetricSource::counting(['control' => [200, 20], 'b' => [200, 60]], ['unsubscribe' => ['control' => [1, 200], 'b' => [12, 200]]]);

        $this->service->tick($this->at(36));

        $exp = $this->service->find($id);
        $this->assertSame('reverted', $exp->status);
        $this->assertStringContainsString('Reverted at the end: the share of members who unsubscribed was probably worse', $exp->decision);
    }

    public function test_a_source_with_no_guardrails_has_none(): void
    {
        $id = $this->started();
        FakeUxMetricSource::counting(['control' => [300, 30], 'b' => [300, 33]], ['anything' => ['control' => [100, 300], 'b' => [200, 300]]]);

        $read = $this->service->read($this->service->find($id), $this->at(5));

        $this->assertSame([], $read['guardrails'], 'the built-in guardrails of the web read do not apply');
    }

    // ---------------------------------------------------------------- words

    public function test_the_words_reach_the_summary_and_the_decision_with_no_funnel_title(): void
    {
        $id = $this->started(['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        FakeUxMetricSource::counting(['control' => [1200, 120], 'b' => [1200, 200]]);

        $this->service->tick($this->at(36));

        $exp = $this->service->find($id);
        $summary = DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('summary');
        $this->assertStringContainsString('1,200 members were counted with the change and 1,200 without.', $summary);
        $this->assertStringContainsString('16.7% clicked a link within a day with the change, 10.0% without', $summary);
        $this->assertStringContainsString('Enough members have been counted to read it.', $summary);
        $this->assertStringNotContainsString('Replying to a post', $summary);
        $this->assertStringNotContainsString('A day of this funnel', $summary);
        $this->assertSame('adopted', $exp->status);
        $this->assertStringContainsString('1,200 members were counted with the change and 1,200 without; 16.7% clicked a link within a day against 10.0%', $exp->decision);
        $this->assertStringNotContainsString('Replying to a post', $exp->decision);
    }

    public function test_no_source_calls_the_members_counted_sent(): void
    {
        // The members counted are fewer than the members the experiment has reached so far, and the tab says
        // "Exposed" for those. A source added later is held to the same words.
        $sources = (array) config('freegle.ux.sources');
        $this->assertGreaterThanOrEqual(3, count($sources), 'the two real sources and the fake one');
        foreach ($sources as $key => $class) {
            $words = app($class)->words((object) ['design' => json_encode(['channel' => 'email', 'window_hours' => 24])]);

            $this->assertDoesNotMatchRegularExpression('/\bsent\b/i', $words['started'].' '.$words['tab_started'], "source $key");
        }
    }

    public function test_one_member_reads_as_one_member(): void
    {
        $id = $this->started();
        FakeUxMetricSource::counting(['control' => [1, 0], 'b' => [1, 1]]);

        $this->service->tick($this->at(1));

        $summary = DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('summary');
        $this->assertStringContainsString('1 member was counted with the change and 1 without.', $summary);
        $this->assertStringContainsString('Too few members counted so far to read it.', $summary);
    }

    public function test_a_click_rate_is_written_to_one_decimal_so_half_a_point_can_be_read(): void
    {
        $id = $this->started(['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        // 3.4 percent against 3.0. Whole percents would print "3% against 3%".
        FakeUxMetricSource::counting(['control' => [20000, 600], 'b' => [20000, 680]]);

        $this->service->tick($this->at(36));

        $summary = DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('summary');
        $this->assertStringContainsString('3.4% clicked a link within a day with the change, 3.0% without (+0.4 points)', $summary);
        $this->assertStringContainsString('3.4% clicked a link within a day against 3.0% (+0.4 points)', $this->service->find($id)->decision);
    }

    public function test_a_skewed_share_warns_in_the_words_of_the_source(): void
    {
        $id = $this->started();
        // The change holds a sixth of the members counted when it was served half.
        FakeUxMetricSource::counting(['control' => [500, 50], 'b' => [100, 12]]);

        $this->service->tick($this->at(5));

        $summary = DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('summary');
        $this->assertStringContainsString('Warning: 17% of the members counted were on the change but about 50% were expected, so the two rates cannot be compared.', $summary);
        $this->assertStringContainsString('Traffic on the change: 50% served, 17% of those counted.', $summary);
        $this->assertStringNotContainsString('of starts', $summary);
    }

    public function test_a_skewed_share_at_the_end_is_inconclusive_in_the_words_of_the_source(): void
    {
        $id = $this->started(['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        // The change looks clearly better, but holds a quarter of the members counted when it was served half.
        FakeUxMetricSource::counting(['control' => [1200, 120], 'b' => [400, 120]]);

        $this->service->tick($this->at(36));

        $exp = $this->service->find($id);
        $this->assertSame('inconclusive', $exp->status);
        $this->assertStringContainsString('Inconclusive at the end, so the change was taken off: the numbers of members counted with each version did not match the share they were served, so the two rates cannot be compared.', $exp->decision);
        $this->assertStringNotContainsString('people who started', $exp->decision);
    }

    public function test_a_web_read_keeps_its_own_words(): void
    {
        $id = $this->service->create($this->attrs(['touches' => ['reply']], ['name' => 'web-words']));
        $this->service->start($id);
        $this->lokiArmsForTheWebExperiment();

        $this->service->tick($this->at(2));

        $summary = DB::table('ux_experiment_reads')->where('experiment_id', $id)->value('summary');
        $this->assertStringContainsString('started "Replying to a post"', $summary);
    }

    private function lokiArmsForTheWebExperiment(): void
    {
        $this->lokiMatrix('/sum by \(arm, flow, action_name, logged_in, start_logged_in, ok\)/', [
            [['arm' => 'control', 'flow' => 'reply', 'action_name' => 'flow_start', 'logged_in' => 'false', 'start_logged_in' => 'false', 'ok' => ''], 120],
            [['arm' => 'b', 'flow' => 'reply', 'action_name' => 'flow_start', 'logged_in' => 'false', 'start_logged_in' => 'false', 'ok' => ''], 120],
        ]);
    }

    public function test_an_end_with_no_exposures_is_inconclusive_in_plain_words(): void
    {
        $id = $this->started(['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        FakeUxMetricSource::counting(['control' => [0, 0], 'b' => [0, 0]]);

        $this->service->tick($this->at(36));

        $exp = $this->service->find($id);
        $this->assertSame('inconclusive', $exp->status);
        $this->assertStringContainsString('Time was up before enough members had been counted with each version to say whether it worked (each needs at least 50)', $exp->decision);
    }

    public function test_a_probably_better_change_that_could_still_lose_a_lot_says_the_run_was_not_long_enough(): void
    {
        $id = $this->started(['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        // Probably better (about 86 percent) but on a small run: if it were not, the expected loss is about
        // 0.6 points, over the 0.5 point bar.
        FakeUxMetricSource::counting(['control' => [60, 30], 'b' => [60, 36]]);

        $this->service->tick($this->at(36));

        $exp = $this->service->find($id);
        $this->assertSame('inconclusive', $exp->status);
        $this->assertStringContainsString('and the run was not long enough to rule that out', $exp->decision);
        $this->assertStringNotContainsString('a day was not enough', $exp->decision);
    }

    // ---------------------------------------------------------------- the split stays where it was put

    public function test_a_fixed_split_is_never_moved_by_the_hourly_tick(): void
    {
        $id = $this->started();
        FakeUxMetricSource::counting(['control' => [300, 90], 'b' => [300, 99]]);
        $exp = $this->service->find($id);
        $read = $this->service->read($exp, $this->at(5));
        $this->assertNotEquals(json_decode($exp->arms, true), $this->service->allocate($read, json_decode($exp->arms, true)), 'the bandit would move this split');

        for ($hour = 1; $hour <= 5; $hour++) {
            $this->service->tick($this->at($hour));
        }

        $after = $this->service->find($id);
        $this->assertSame('running', $after->status);
        $this->assertEquals(['control' => [0, 49], 'b' => [50, 99]], json_decode($after->arms, true));
    }

    // ---------------------------------------------------------------- a source that fails

    public function test_a_source_that_fails_is_a_stale_read_and_the_experiment_goes_on(): void
    {
        $id = $this->started();
        FakeUxMetricSource::counting(['control' => [300, 30], 'b' => [300, 33]]);
        $this->service->tick($this->at(1));
        $this->assertSame(1, DB::table('ux_experiment_reads')->where('experiment_id', $id)->count());

        FakeUxMetricSource::$fails = new \RuntimeException('database down');
        $lines = $this->service->tick($this->at(1.5));

        $this->assertSame('running', $this->service->find($id)->status);
        $this->assertSame(1, DB::table('ux_experiment_reads')->where('experiment_id', $id)->count(), 'a stale read is not stored as a new one');
        $this->assertCount(1, $lines);
    }

    public function test_a_source_that_fails_past_the_end_waits_and_then_gives_up(): void
    {
        $id = $this->started(['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        FakeUxMetricSource::$fails = new \RuntimeException('database down');

        $lines = $this->service->tick($this->at(37));
        $this->assertSame('running', $this->service->find($id)->status);
        $this->assertStringContainsString('waiting for it', implode(' ', $lines));

        $this->service->tick($this->at(36 + 6));
        $exp = $this->service->find($id);
        $this->assertSame('inconclusive', $exp->status);
        $this->assertStringContainsString('could not be read', $exp->decision);
    }

    // ---------------------------------------------------------------- the holdout after adoption

    public function test_the_holdout_look_counts_from_the_decision_and_is_not_capped_at_the_end(): void
    {
        $id = $this->started(['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        FakeUxMetricSource::counting(['control' => [1200, 120], 'b' => [1200, 200]]);
        $this->service->tick($this->at(36));
        $this->assertSame('adopted', $this->service->find($id)->status);
        $decidedAt = Carbon::parse($this->service->find($id)->decided_at, 'UTC');
        FakeUxMetricSource::$calls = [];

        $lines = $this->service->checkAdopted($this->at(36 + 24 + 1));

        $this->assertCount(1, FakeUxMetricSource::$calls);
        $this->assertEquals($decidedAt, FakeUxMetricSource::$calls[0]['since']);
        $this->assertEquals(Carbon::now(), FakeUxMetricSource::$calls[0]['now']);
        $this->assertStringContainsString('holdout checked', implode(' ', $lines));
    }

    public function test_the_holdout_reverts_an_adoption_when_the_old_version_does_better_on_enough_members(): void
    {
        $id = $this->started(['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        FakeUxMetricSource::counting(['control' => [1200, 120], 'b' => [1200, 200]]);
        $this->service->tick($this->at(36));

        // Over the next two looks the holdout is clearly better: it has 300 counted, as the adopted arm does.
        FakeUxMetricSource::counting(['control' => [300, 90], 'b' => [300, 30]]);
        $this->service->checkAdopted($this->at(60));
        $this->service->checkAdopted($this->at(84));

        $this->assertSame('reverted', $this->service->find($id)->status);
    }

    public function test_the_text_of_a_holdout_that_reverts_an_adoption_uses_the_words_and_the_rates_of_the_source(): void
    {
        $id = $this->started(['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        FakeUxMetricSource::counting(['control' => [1200, 120], 'b' => [1200, 200]]);
        $this->service->tick($this->at(36));

        FakeUxMetricSource::counting(['control' => [300, 90], 'b' => [300, 30]]);
        $this->service->checkAdopted($this->at(60));
        $this->service->checkAdopted($this->at(84));

        $decision = $this->service->find($id)->decision;
        $this->assertStringContainsString('the members who kept the old version did better', $decision);
        $this->assertStringContainsString('30.0% clicked a link within a day with the old version against 10.0% with the change', $decision);
    }

    public function test_early_holdout_looks_with_nobody_matured_strike_nothing(): void
    {
        $id = $this->started(['window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        FakeUxMetricSource::counting(['control' => [1200, 120], 'b' => [1200, 200]]);
        $this->service->tick($this->at(36));

        FakeUxMetricSource::counting(['control' => [0, 0], 'b' => [0, 0]]);
        $this->service->checkAdopted($this->at(60));
        $this->service->checkAdopted($this->at(84));

        $this->assertSame('adopted', $this->service->find($id)->status);
    }

    // ---------------------------------------------------------------- the config key

    /** @return array<string, array{0: string}> */
    public static function salts(): array
    {
        // "0" is the natural first number for a re-run, and PHP counts the string "0" as empty.
        return ['a salt of letters and a digit' => ['r2'], 'a salt of zero' => ['0']];
    }

    #[DataProvider('salts')]
    public function test_the_salt_is_published_while_running_and_kept_once_adopted(string $salt): void
    {
        $id = $this->started(['salt' => $salt, 'window_hours' => 24, 'intake_hours' => 12, 'max_hours' => 36]);
        $this->assertSame($salt, $this->configEntry()['salt'], 'a salt left out of the entry gives the run the buckets of the run before it');

        FakeUxMetricSource::counting(['control' => [1200, 120], 'b' => [1200, 200]]);
        $this->service->tick($this->at(36));

        $entry = $this->configEntry();
        $this->assertSame('adopted', $this->service->find($id)->status);
        $this->assertTrue($entry['adopted']);
        $this->assertSame($salt, $entry['salt'], 'adoption keeps the salt or it would put everyone in a different bucket');
        $this->assertEquals(['b' => [0, 89], 'control' => [90, 99]], $entry['arms']);
    }

    public function test_an_experiment_with_no_salt_publishes_none(): void
    {
        $this->started();

        $this->assertArrayNotHasKey('salt', $this->configEntry());
    }

    public function test_stopping_takes_the_entry_out_of_the_key_at_once(): void
    {
        $id = $this->started();

        $this->service->stop($id);

        $this->assertSame([], json_decode(DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->value('value'), true));
    }

    public function test_the_server_reads_the_salt_the_engine_published(): void
    {
        $this->started(['salt' => 'r2']);
        UxArm::flush();
        config()->set('ux_switches', [['name' => 'srv-test']]);
        $arms = json_decode($this->service->find('srv-test')->arms, true);

        // A member the salt moves to the other arm: only a server that reads the published salt puts them there.
        $moved = null;
        for ($id = 1; $id <= 500 && $moved === null; $id++) {
            if (ExperimentBucket::armIn($id, 'srv-test', $arms, 'r2') !== ExperimentBucket::armIn($id, 'srv-test', $arms, '')) {
                $moved = $id;
            }
        }
        $this->assertNotNull($moved, 'some member in 500 changes arm with the salt');

        $this->assertSame(ExperimentBucket::armIn($moved, 'srv-test', $arms, 'r2') ?? '', UxArm::for($moved, 'srv-test'));
        $this->assertNotSame(ExperimentBucket::armIn($moved, 'srv-test', $arms, '') ?? '', UxArm::for($moved, 'srv-test'), 'and not the arm the same member would have had with no salt');
    }

    // ---------------------------------------------------------------- the command

    /** @return array<string, mixed> */
    private function createArgs(array $over = []): array
    {
        return $over + [
            'action' => 'create', '--flow' => 'reply', '--name' => 'cli-design', '--title' => 'Ask repliers to confirm',
            '--hypothesis' => 'Repliers who never meant to take the item waste time.', '--change' => 'A question is asked first.',
            '--expected' => 'More handovers.',
        ];
    }

    public function test_the_command_creates_a_draft_with_the_design_it_is_given(): void
    {
        $json = json_encode(['channel' => 'email', 'type' => 'UnifiedDigestDaily', 'source' => 'fake', 'window_hours' => 24, 'salt' => 'r2']);

        $this->artisan('ux:experiment', $this->createArgs(['--design' => $json]))->assertExitCode(0);

        $design = json_decode($this->service->find('cli-design')->design, true);
        $this->assertSame('email', $design['channel']);
        $this->assertSame('r2', $design['salt']);
        $this->assertTrue($design['fixed_split']);
    }

    public function test_touches_and_expected_points_win_over_the_same_keys_in_the_design(): void
    {
        $json = json_encode(['channel' => 'server', 'source' => 'fake', 'window_hours' => 24, 'expected_points' => 9, 'touches' => ['login']]);

        $this->artisan('ux:experiment', $this->createArgs(['--design' => $json, '--expected-points' => '0.5', '--touches' => 'reply']))->assertExitCode(0);

        $design = json_decode($this->service->find('cli-design')->design, true);
        $this->assertSame(0.5, $design['expected_points']);
        $this->assertSame(['reply'], $design['touches']);
        $this->assertSame('server', $design['channel'], 'the keys the options do not name are kept');
    }

    public function test_a_design_that_is_not_a_json_object_is_refused_and_nothing_is_made(): void
    {
        foreach (['not json', '[1,2]', '"text"', '5'] as $i => $bad) {
            $this->artisan('ux:experiment', $this->createArgs(['--name' => "cli-bad-$i", '--design' => $bad]))
                ->expectsOutputToContain('--design must be a JSON object')
                ->assertExitCode(1);
            $this->assertNull($this->service->find("cli-bad-$i"));
        }
    }

    public function test_a_wrong_value_inside_the_design_is_refused_in_plain_words(): void
    {
        $this->artisan('ux:experiment', $this->createArgs(['--design' => json_encode(['channel' => 'server', 'source' => 'fake'])]))
            ->expectsOutputToContain('design.window_hours is needed with design.source')
            ->assertExitCode(1);

        $this->assertNull($this->service->find('cli-design'));
    }

    public function test_an_empty_design_option_is_no_design(): void
    {
        $this->artisan('ux:experiment', $this->createArgs(['--design' => '']))->assertExitCode(0);

        $this->assertNull($this->service->find('cli-design')->design);
    }

    // ---------------------------------------------------------------- a source the code no longer has

    public function test_an_experiment_whose_source_has_gone_is_left_alone_by_the_tick_and_says_why(): void
    {
        $id = $this->started();
        config()->set('freegle.ux.sources', []);

        $lines = $this->service->tick($this->at(2));

        $this->assertSame('running', $this->service->find($id)->status);
        $this->assertStringContainsString('srv-test: error, left alone', implode(' ', $lines));
        $this->assertStringContainsString('names the metric source "fake", which this code does not have', implode(' ', $lines));
    }

    public function test_an_experiment_whose_source_has_gone_cannot_be_started_again(): void
    {
        $id = $this->started();
        $this->service->stop($id);
        config()->set('freegle.ux.sources', []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('which this code does not have');
        $this->service->start($id);
    }

    public function test_a_restart_keeps_a_fixed_split_where_the_creator_put_it(): void
    {
        // A canary: a tenth of members get the change. A restart must not quietly make it half.
        $id = $this->service->create($this->attrs($this->design(), ['arms' => ['control' => [0, 89], 'b' => [90, 99]]]));
        $this->service->start($id);
        $this->service->stop($id);

        $exp = $this->service->start($id);

        $this->assertEquals(['control' => [0, 89], 'b' => [90, 99]], json_decode($exp->arms, true));
        $this->assertEquals(['control' => [0, 89], 'b' => [90, 99]], $this->configEntry()['arms']);
    }

    public function test_a_restart_still_evens_out_the_split_of_a_change_to_the_site(): void
    {
        $id = $this->service->create($this->attrs(['touches' => ['reply']], ['name' => 'web-restart']));
        $this->service->start($id);
        // The bandit last left it skewed.
        DB::table('ux_experiments')->where('id', $id)->update(['arms' => json_encode(['control' => [0, 63], 'b' => [64, 99]])]);
        $this->service->stop($id);

        $exp = $this->service->start($id);

        $this->assertEquals(['control' => [0, 49], 'b' => [50, 99]], json_decode($exp->arms, true));
    }
}
