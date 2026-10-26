<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxExperimentRefused;
use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * A web experiment judged on a client event (design.primary_event) rather than on finishing its
 * funnel: the event per start is the main measure, and finishing the funnel is a guardrail.
 */
class UxExperimentPrimaryEventTest extends TestCase
{
    use FakesUxLoki;

    private const HOURLY = '/sum by \(arm, flow, action_name, logged_in, start_logged_in, ok\)/';

    private const EVENTS = '/sum by \(arm, logged_in\)/';

    private UxExperimentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ux_cleanups', []);
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->fakeLoki();
        foreach (['ux_experiment_reads', 'ux_experiments', 'ux_flow_outcome_daily'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->delete();
        config()->set('freegle.ux.experiments.min_starts_per_arm', 50);
        $this->service = app(UxExperimentService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function attrs(array $over = []): array
    {
        return $over + [
            'flow' => 'reply', 'name' => 'reply-job-interstitial', 'title' => 'Show job adverts after a reply',
            'hypothesis' => 'People who have just replied will look at local jobs if we show them.',
            'change' => 'A pop-up with local job adverts once a reply is sent.',
            'expected' => 'More job advert clicks, replies finished as often.',
            'design' => ['primary_event' => 'job_ad_click', 'touches' => ['reply']],
        ];
    }

    private function running(array $over = []): int
    {
        $id = $this->service->create($this->attrs($over));
        $this->service->start($id);

        return $id;
    }

    /** Starts and completes on the reply flow, and job advert clicks, by arm. */
    private function loki(array $flow, array $clicks): void
    {
        $series = [];
        foreach ($flow as $arm => [$starts, $completes]) {
            foreach (['flow_start' => $starts, 'flow_complete' => $completes] as $event => $n) {
                $series[] = [['arm' => $arm, 'flow' => 'reply', 'action_name' => $event, 'logged_in' => 'true',
                    'start_logged_in' => 'true', 'ok' => ''], $n];
            }
        }
        $this->lokiMatrix(self::HOURLY, $series);
        $this->lokiMatrix(self::EVENTS, array_map(fn ($arm) => [['arm' => $arm, 'logged_in' => 'true'], $clicks[$arm]], array_keys($clicks)));
    }

    private function seedVolume(string $flow, int $startedPerDay, float $rate): void
    {
        for ($i = 1; $i <= 7; $i++) {
            DB::table('ux_flow_outcome_daily')->insert([
                'date' => Carbon::now()->subDays($i)->toDateString(), 'flow' => $flow,
                'started' => $startedPerDay, 'completed' => (int) round($startedPerDay * $rate),
            ]);
        }
    }

    public function test_the_design_keeps_a_known_event_and_a_base_rate(): void
    {
        $id = $this->service->create($this->attrs(['design' => ['primary_event' => 'job_ad_click', 'base_rate' => 0.02]]));

        $design = json_decode($this->service->find($id)->design, true);
        $this->assertSame('job_ad_click', $design['primary_event']);
        $this->assertSame(0.02, $design['base_rate']);
    }

    public function test_the_app_store_click_event_is_listed(): void
    {
        $id = $this->service->create($this->attrs(['design' => ['primary_event' => 'app_store_click', 'base_rate' => 0.01]]));

        $design = json_decode($this->service->find($id)->design, true);
        $this->assertSame('app_store_click', $design['primary_event']);
    }

    public function test_the_user_rated_event_is_listed(): void
    {
        $id = $this->service->create($this->attrs(['flow' => 'chat_outcome', 'name' => 'rate-on-return', 'design' => ['primary_event' => 'user_rated', 'base_rate' => 0.4]]));

        $design = json_decode($this->service->find($id)->design, true);
        $this->assertSame('user_rated', $design['primary_event']);
    }

    public function test_an_event_that_is_not_listed_is_refused_because_it_would_reach_logql(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('design.primary_event must be one of: job_ad_click');

        $this->service->create($this->attrs(['design' => ['primary_event' => 'x" } |= "']]));
    }

    public function test_a_base_rate_must_be_a_share(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->create($this->attrs(['design' => ['primary_event' => 'job_ad_click', 'base_rate' => 2]]));
    }

    public function test_the_read_counts_the_event_per_start_and_guards_finishing_the_funnel(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 16:00:00');
        $this->loki(['control' => [1000, 600], 'b' => [1000, 590]], ['control' => 15, 'b' => 40]);

        $this->service->tick();

        $read = DB::table('ux_experiment_reads')->where('experiment_id', $id)->first();
        $metrics = json_decode($read->metrics, true);
        $this->assertSame(40, $metrics['arms']['b']['events']);
        $this->assertEqualsWithDelta(0.04, $metrics['primary']['b']['variant_rate'], 1e-9);
        $this->assertEqualsWithDelta(0.015, $metrics['primary']['b']['control_rate'], 1e-9);
        $this->assertSame(2.5, $metrics['primary']['b']['diff_points']);
        $this->assertGreaterThan(0.99, $metrics['primary']['b']['p_better']);
        // Finishing the reply is now a guardrail, and the count of guardrails includes it.
        $own = collect($metrics['guardrails'])->firstWhere('name', 'flow:reply');
        $this->assertSame('finishing "Replying to a post"', $own['label']);
        $this->assertSame(4, $own['k']);
        $this->assertStringContainsString('4% clicked a job advert with the change, 1.5% without (+2.5 points)', $read->summary);

        // The event was read from action lines, by this experiment's arms, and cut where the starts are.
        $q = collect($this->lokiQueries)->first(fn ($q) => preg_match(self::EVENTS, $q));
        $this->assertStringContainsString('event_type="action"', $q);
        $this->assertStringContainsString('action_name="job_ad_click"', $q);
        $this->assertStringContainsString('reply-job-interstitial:(?P<arm>b|control)', $q);
    }

    public function test_clicks_in_a_chat_are_read_with_only_the_chat_and_pop_up_clicks(): void
    {
        $id = $this->running(['name' => 'reply-job-chat', 'design' => ['primary_event' => 'job_ad_click_chat', 'touches' => ['reply']]]);
        Carbon::setTestNow('2026-10-14 16:00:00');
        $this->loki(['control' => [1000, 600], 'b' => [1000, 600]], ['control' => 60, 'b' => 100]);

        $this->service->tick();

        $q = collect($this->lokiQueries)->first(fn ($q) => preg_match(self::EVENTS, $q));
        // The action is the plain click; the filter keeps the clicks in a chat and from the pop-up.
        $this->assertStringContainsString('|= "job_ad_click"', $q);
        $this->assertStringContainsString('action_name="job_ad_click"', $q);
        $this->assertStringContainsString('json action_name, variant, logged_in, page, context', $q);
        $this->assertStringContainsString('| page="chats-id" or context="reply_interstitial"', $q);
        $read = DB::table('ux_experiment_reads')->where('experiment_id', $id)->first();
        $this->assertStringContainsString('10% clicked a job advert in a chat or after replying with the change, 6% without', $read->summary);
    }

    public function test_the_site_wide_click_has_no_chat_filter(): void
    {
        $this->running();
        Carbon::setTestNow('2026-10-14 16:00:00');

        $this->service->tick();

        $q = collect($this->lokiQueries)->first(fn ($q) => preg_match(self::EVENTS, $q));
        $this->assertStringNotContainsString('page=', $q);
        $this->assertStringNotContainsString('context=', $q);
    }

    public function test_more_clicks_are_adopted_when_replies_finish_as_often(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 18:00:00');
        $this->loki(['control' => [3000, 1800], 'b' => [3000, 1795]], ['control' => 45, 'b' => 150]);

        $this->service->tick();

        $exp = $this->service->find($id);
        $this->assertSame('adopted', $exp->status);
        $this->assertStringContainsString('clicked a job advert', $exp->decision);
    }

    public function test_more_clicks_do_not_save_a_change_that_stops_people_finishing_replies(): void
    {
        $id = $this->running();
        Carbon::setTestNow('2026-10-14 18:00:00');
        $this->loki(['control' => [3000, 1800], 'b' => [3000, 1500]], ['control' => 45, 'b' => 150]);

        $this->service->tick();

        $exp = $this->service->find($id);
        $this->assertSame('reverted', $exp->status);
        $this->assertStringContainsString('finishing "Replying to a post" got worse', $exp->decision);
    }

    public function test_more_events_than_starts_count_as_one_each(): void
    {
        $this->running();
        Carbon::setTestNow('2026-10-14 16:00:00');
        $this->loki(['control' => [100, 60], 'b' => [100, 60]], ['control' => 5, 'b' => 400]);

        $this->service->tick();

        $metrics = json_decode(DB::table('ux_experiment_reads')->value('metrics'), true);
        $this->assertEqualsWithDelta(1.0, $metrics['primary']['b']['variant_rate'], 1e-9);
    }

    public function test_the_power_gate_uses_the_events_own_rate_and_is_skipped_without_one(): void
    {
        // Plenty of replies, but on a 2% click rate a day cannot show a tenth of a point.
        $this->seedVolume('reply', 6000, 0.6);
        $weak = $this->service->create($this->attrs(['design' => ['primary_event' => 'job_ad_click', 'base_rate' => 0.02, 'expected_points' => 0.1]]));
        try {
            $this->service->start($weak);
            $this->fail('a click experiment with no power was started');
        } catch (UxExperimentRefused $e) {
            $this->assertStringContainsString('would adopt a change of 0.1 points only', $e->getMessage());
        }

        $strong = $this->service->start($this->service->create($this->attrs([
            'name' => 'reply-job-strong', 'design' => ['primary_event' => 'job_ad_click', 'base_rate' => 0.02, 'expected_points' => 1.5]])));
        $this->assertGreaterThan(0.3, json_decode($strong->design, true)['power']);
        $this->service->stop($strong->id);

        // Without a base rate the rollup's finishing rate would be the wrong one, so no check is made.
        $none = $this->service->start($this->service->create($this->attrs(['name' => 'reply-job-none'])));
        $this->assertArrayNotHasKey('power', json_decode($none->design, true));
        $this->assertArrayNotHasKey('mde_points', json_decode($none->result, true) ?: []);
    }

    public function test_a_funnel_experiment_sends_no_event_query(): void
    {
        $this->running(['name' => 'reply-plain', 'design' => ['touches' => ['reply']]]);
        Carbon::setTestNow('2026-10-14 16:00:00');

        $this->service->tick();

        $this->assertEmpty(array_filter($this->lokiQueries, fn ($q) => preg_match(self::EVENTS, $q)));
    }
}
