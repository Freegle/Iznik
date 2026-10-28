<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxRollupService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * UxRollupService turns a day of client telemetry in Loki, and a day of MySQL history, into the
 * ux_* tables. Loki is faked with realistic matrix and stream answers; MySQL is real.
 */
class UxRollupServiceTest extends TestCase
{
    use FakesUxLoki;

    private const DAY = '2026-03-05';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-06 02:20:00');
        $this->fakeLoki();
        foreach (['ux_flow_daily', 'ux_flow_outcome_daily', 'ux_field_daily', 'ux_flow_signal_daily', 'ux_component_daily',
            'ux_feature_adoption_daily', 'ux_search_daily', 'ux_regret_daily',
            'ux_telemetry_health_daily', 'ux_sample_sessions'] as $t) {
            DB::table($t)->delete();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): UxRollupService
    {
        return app(UxRollupService::class);
    }

    private function flowPattern(string $flow, string $action, string $filter = ''): string
    {
        return '/count_over_time\(\{[^}]*event_type="flow"\} \| json [a-z_, ]+ \| flow="'.$flow.'" \| action_name="'.$action.'"'.preg_quote($filter, '/').' \[1d\]/';
    }

    private function dims(array $extra = []): array
    {
        return array_merge(['device_class' => 'mobile', 'logged_in' => 'true', 'is_app' => 'false', 'variant' => ''], $extra);
    }

    public function test_flow_step_events_merge_into_one_row_per_step_and_dimension(): void
    {
        $this->lokiMatrix($this->flowPattern('give', 'step_enter'), [
            [$this->dims(['step' => 'give-mobile-photos', 'first_visit' => 'true']), 100],
            [$this->dims(['step' => 'give-mobile-photos', 'first_visit' => 'false']), 12],
        ]);
        $this->lokiMatrix($this->flowPattern('give', 'step_complete'), [[$this->dims(['step' => 'give-mobile-photos']), 80]]);
        $this->lokiMatrix($this->flowPattern('give', 'step_back'), [[$this->dims(['step' => 'give-mobile-photos']), 7]]);
        $this->lokiMatrix($this->flowPattern('give', 'flow_abandon'), [[$this->dims(['last_step' => 'give-mobile-photos']), 11]]);
        $this->lokiMatrix($this->flowPattern('give', 'field_revisit'), [[$this->dims(['step' => 'give-mobile-photos']), 5]]);
        $this->lokiMatrix($this->flowPattern('give', 'validation_failed'), [[$this->dims(['step' => 'give-mobile-photos']), 3]]);
        $this->lokiMatrix($this->flowPattern('give', 'help_sought'), [[$this->dims(['step' => 'give-mobile-photos']), 2]]);
        $this->lokiMatrix('/quantile_over_time\(0\.5, .*flow="give" \| action_name="step_complete" \| unwrap dwell_ms/', [[$this->dims(['step' => 'give-mobile-photos']), 4200.4]]);
        $this->lokiMatrix('/quantile_over_time\(0\.9, .*flow="give" \| action_name="step_complete" \| unwrap dwell_ms/', [[$this->dims(['step' => 'give-mobile-photos']), 31000]]);

        $this->assertTrue($this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC')));

        $rows = DB::table('ux_flow_daily')->where('date', self::DAY)->get();
        $this->assertCount(1, $rows);
        $r = $rows[0];
        $this->assertSame('give', $r->flow);
        $this->assertSame('give-mobile-photos', $r->step);
        $this->assertSame('mobile', $r->device_class);
        $this->assertSame(1, (int) $r->logged_in);
        $this->assertSame(0, (int) $r->is_app);
        $this->assertSame([100, 12, 80, 7, 11, 5, 3, 2], [
            (int) $r->entered, (int) $r->reentered, (int) $r->completed, (int) $r->backed,
            (int) $r->abandoned, (int) $r->revisits, (int) $r->validation_failures, (int) $r->help_sought,
        ]);
        $this->assertSame(4200, (int) $r->dwell_p50_ms);
        $this->assertSame(31000, (int) $r->dwell_p90_ms);
    }

    public function test_an_idle_return_takes_its_abandon_back_off_the_step(): void
    {
        $this->lokiMatrix($this->flowPattern('give', 'step_enter'), [[$this->dims(['step' => 'give-details', 'first_visit' => 'true']), 10]]);
        $this->lokiMatrix($this->flowPattern('give', 'flow_abandon'), [[$this->dims(['last_step' => 'give-details']), 4]]);
        $this->lokiMatrix($this->flowPattern('give', 'flow_resume', ' | reason="idle"'), [[$this->dims(['step' => 'give-details']), 3]]);

        $this->assertTrue($this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC')));

        $this->assertSame(1, (int) DB::table('ux_flow_daily')->where('date', self::DAY)->where('step', 'give-details')->value('abandoned'));
    }

    public function test_flow_queries_never_carry_user_input_and_split_per_flow_and_event(): void
    {
        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $this->assertTrue($this->lokiSent('event_type="flow"', 'flow="reply"', 'action_name="flow_submit_result"'));
        $this->assertTrue($this->lokiSent('flow="chat_outcome"', 'action_name="step_enter"'));
        foreach ($this->lokiQueries as $q) {
            // The one $ allowed is a route regex's end anchor, just before its closing quote.
            $this->assertStringNotContainsString('$', str_replace(')$"', ')"', $q));
        }
    }

    public function test_outcomes_are_counted_per_flow_with_submit_result_split_by_ok(): void
    {
        $d = $this->dims();
        $this->lokiMatrix($this->flowPattern('ask', 'flow_start'), [[$d, 50]]);
        $this->lokiMatrix($this->flowPattern('ask', 'flow_submit'), [[$d, 40]]);
        $this->lokiMatrix($this->flowPattern('ask', 'flow_submit_result'), [
            [$d + ['ok' => 'true'], 35],
            [$d + ['ok' => 'false'], 5],
        ]);
        $this->lokiMatrix($this->flowPattern('ask', 'flow_complete'), [[$d, 33]]);
        $this->lokiMatrix($this->flowPattern('ask', 'flow_abandon'), [[$d, 14]]);
        // Restored drafts are resumes; idle returns take their abandon back off instead.
        $this->lokiMatrix($this->flowPattern('ask', 'flow_resume', ' | reason!="idle"'), [[$d, 4]]);
        $this->lokiMatrix($this->flowPattern('ask', 'flow_resume', ' | reason="idle"'), [[$d, 3]]);
        $this->lokiMatrix('/quantile_over_time\(0\.5, .*flow="ask" \| action_name="flow_complete" \| unwrap dwell_ms/', [[$d, 90000]]);
        $this->lokiMatrix('/quantile_over_time\(0\.9, .*flow="ask" \| action_name="flow_complete" \| unwrap dwell_ms/', [[$d, 240000]]);

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $r = DB::table('ux_flow_outcome_daily')->where('date', self::DAY)->where('flow', 'ask')->first();
        $this->assertNotNull($r);
        $this->assertSame([50, 40, 35, 5, 33, 11, 4], [
            (int) $r->started, (int) $r->submitted, (int) $r->submit_ok, (int) $r->submit_failed,
            (int) $r->completed, (int) $r->abandoned, (int) $r->resumed,
        ]);
        $this->assertSame(90000, (int) $r->total_dwell_p50_ms);
        $this->assertSame(240000, (int) $r->total_dwell_p90_ms);
    }

    public function test_field_events_are_counted_per_field(): void
    {
        $k = ['step' => 'give-mobile-details', 'field' => 'deadline', 'device_class' => 'desktop'];
        $this->lokiMatrix($this->flowPattern('give', 'field_revisit'), [[$k + ['logged_in' => 'true', 'is_app' => 'false', 'variant' => ''], 9]]);
        $byField = fn (string $action) => '/by \(step, field, device_class\) \(count_over_time\(.*flow="give" \| action_name="'.$action.'"/';
        $this->lokiMatrix($byField('field_revisit'), [[$k, 9]]);
        $this->lokiMatrix($byField('validation_failed'), [[$k, 4]]);
        $this->lokiMatrix($byField('choice_switched'), [[$k, 2]]);

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $r = DB::table('ux_field_daily')->where('date', self::DAY)->first();
        $this->assertSame('give', $r->flow);
        $this->assertSame('deadline', $r->field);
        $this->assertSame([9, 4, 2], [(int) $r->revisits, (int) $r->validation_failures, (int) $r->choice_switches]);
    }

    /** The signal queries: people who showed it, and (with the join to flow_complete) those who finished. */
    private function signalPattern(string $flow, string $actions, bool $finished): string
    {
        $head = '/^count by \([^)]*\) \(sum by \(flow_instance_id, [^)]*\) \(count_over_time\(\{[^}]*\} \| json [a-z_, ]+ \| flow="'.$flow.'" \| action_name'.$actions;
        $tail = ' \| flow_instance_id!="" \[1d\]\)\)';

        return $finished
            ? $head.$tail.' and on \(flow_instance_id\) /'
            : $head.$tail.'\)$/';
    }

    public function test_signals_count_visits_and_how_many_of_them_went_on_to_finish(): void
    {
        $at = $this->dims(['step' => 'give-mobile-details']);
        $this->lokiMatrix($this->signalPattern('give', '="step_enter"', false), [[$at, 400]]);
        $this->lokiMatrix($this->signalPattern('give', '="step_enter"', true), [[$at, 300]]);
        $this->lokiMatrix($this->signalPattern('give', '="step_back"', false), [[$at, 70]]);
        $this->lokiMatrix($this->signalPattern('give', '="step_back"', true), [[$at, 11]]);
        $this->lokiMatrix($this->signalPattern('give', '="help_sought"', false), [[$at, 9]]);
        $field = $at + ['field' => 'deadline'];
        $this->lokiMatrix($this->signalPattern('give', '=~"\^\(field_revisit\|validation_failed\|choice_switched\)\$"', false), [[$field, 50]]);
        $this->lokiMatrix($this->signalPattern('give', '=~"\^\(field_revisit\|validation_failed\|choice_switched\)\$"', true), [[$field, 45]]);

        $this->assertTrue($this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC')));

        $rows = DB::table('ux_flow_signal_daily')->where('date', self::DAY)->get()->keyBy(fn ($r) => $r->signal.'|'.$r->field);
        $this->assertSame([400, 300], [(int) $rows['reached|']->people, (int) $rows['reached|']->finished]);
        $this->assertSame([70, 11], [(int) $rows['backed|']->people, (int) $rows['backed|']->finished]);
        // Nobody who asked for help finished, and that is still a row, not a missing one.
        $this->assertSame([9, 0], [(int) $rows['help|']->people, (int) $rows['help|']->finished]);
        $this->assertSame([50, 45], [(int) $rows['field|deadline']->people, (int) $rows['field|deadline']->finished]);
        $this->assertSame('give-mobile-details', $rows['backed|']->step);
        $this->assertSame('mobile', $rows['backed|']->device_class);
        $this->assertSame(1, (int) $rows['backed|']->logged_in);
    }

    public function test_the_signal_join_is_on_the_visit_and_carries_no_user_input(): void
    {
        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $this->assertTrue($this->lokiSent('and on (flow_instance_id)', 'flow="give"', 'action_name="step_back"', 'action_name="flow_complete"'));
        $this->assertTrue($this->lokiSent('flow="chat_outcome"', 'action_name="step_enter"', 'count by (step,'));
    }

    public function test_a_missing_signal_table_skips_it_and_still_writes_the_rest(): void
    {
        $this->lokiMatrix($this->flowPattern('give', 'step_enter'), [[$this->dims(['step' => 'give', 'first_visit' => 'true']), 5]]);
        \Illuminate\Support\Facades\Schema::drop('ux_flow_signal_daily');

        try {
            $this->assertTrue($this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC')));
            $this->assertSame(5, (int) DB::table('ux_flow_daily')->where('date', self::DAY)->value('entered'));
        } finally {
            (require base_path('database/migrations/2026_10_21_000001_create_ux_flow_signal_daily_table.php'))->up();
        }
    }

    public function test_component_clicks_dead_rage_repeat_and_scaled_impressions(): void
    {
        $k = ['page_route' => 'browse-term', 'component' => 'PostCard', 'device_class' => 'desktop'];
        $this->lokiMatrix('/interaction_type="click" \| device_class="desktop" \[1d\]/', [[$k, 200]]);
        $this->lokiMatrix('/device_class="desktop" \| interactive="false" \[1d\]/', [[$k, 30]]);
        $this->lokiMatrix('/device_class="desktop" \| rage="true" \[1d\]/', [[$k, 6]]);
        $this->lokiMatrix('/device_class="desktop" \| repeat_no_api="true" \[1d\]/', [[$k, 4]]);
        $this->lokiLines('/^\{[^}]*event_type="impressions"\}$/', [
            ['page_route' => 'browse-term', 'device_class' => 'desktop', 'components' => ['PostCard' => 5, 'Banner' => 1], 'sample_rate' => 0.1],
            ['page_route' => 'browse-term', 'device_class' => 'desktop', 'components' => ['PostCard' => 2]],
        ]);

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $card = DB::table('ux_component_daily')->where('date', self::DAY)->where('component', 'PostCard')->first();
        $this->assertSame([200, 30, 6, 4, 52], [
            (int) $card->clicks, (int) $card->dead_clicks, (int) $card->rage_clicks, (int) $card->repeated_intent, (int) $card->impressions,
        ]);
        $this->assertSame('browse-term', $card->page);
        // A component seen but never clicked still gets a row.
        $banner = DB::table('ux_component_daily')->where('date', self::DAY)->where('component', 'Banner')->first();
        $this->assertSame([0, 10], [(int) $banner->clicks, (int) $banner->impressions]);
    }

    public function test_feature_adoption_counts_distinct_users_and_sessions_against_active_users(): void
    {
        config()->set('freegle.ux.features', ['noticeboards' => ['noticeboards', 'noticeboards-id']]);
        // Anchored: a Loki label filter regex matches anywhere in the value, so an unanchored
        // "give" also counts every give-* page.
        $this->lokiLines('/event_type="page_view".*page_route=~"\^\(\?:noticeboards\|noticeboards-id\)\$"/', [
            ['page_route' => 'noticeboards', 'user_id' => 11, 'session_id' => 's1'],
            ['page_route' => 'noticeboards-id', 'user_id' => 11, 'session_id' => 's1'],
            ['page_route' => 'noticeboards', 'user_id' => 12, 'session_id' => 's2'],
            ['page_route' => 'noticeboards', 'session_id' => 's3'],
        ]);
        $a = $this->createTestUser(['lastaccess' => self::DAY.' 10:00:00']);
        $b = $this->createTestUser(['lastaccess' => self::DAY.' 23:59:00']);
        $c = $this->createTestUser(['lastaccess' => '2026-03-04 23:59:00']);
        $expectedActive = DB::table('users')->whereBetween('lastaccess', [self::DAY.' 00:00:00', self::DAY.' 23:59:59'])->count();

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $r = DB::table('ux_feature_adoption_daily')->where('date', self::DAY)->where('feature', 'noticeboards')->first();
        $this->assertSame([2, 3, $expectedActive], [(int) $r->using_users, (int) $r->sessions_using, (int) $r->active_users]);
        $this->assertGreaterThanOrEqual(2, $expectedActive);
    }

    public function test_search_counts_zero_results_re_searches_and_browse_scroll_depth(): void
    {
        $this->lokiMatrix('/source="vector_search"\} \| json returned \| returned="0" \[1d\]/', [[[], 7]]);
        $this->lokiMatrix('/count_over_time\(\{app="freegle", source="vector_search"\} \[1d\]/', [[[], 40]]);
        $this->lokiLines('/source="vector_search"\} \| json$/', [
            ['user_id' => 5, 'timestamp' => '2026-03-05T10:00:00Z', 'term' => 'sofa'],
            ['user_id' => 5, 'timestamp' => '2026-03-05T10:00:40Z', 'term' => 'couch'],
            ['user_id' => 5, 'timestamp' => '2026-03-05T10:05:00Z', 'term' => 'bed'],
            ['user_id' => 0, 'timestamp' => '2026-03-05T10:00:00Z', 'term' => 'x'],
            ['user_id' => 0, 'timestamp' => '2026-03-05T10:00:05Z', 'term' => 'y'],
        ]);
        DB::table('browse_scroll_depth')->where('created_at', '>=', self::DAY)->where('created_at', '<', '2026-03-06')->delete();
        foreach ([[10, 10, 'browse'], [3, 50, 'browse'], [9, 10, null], [4, 4, 'search']] as $i => [$max, $avail, $ctx]) {
            DB::table('browse_scroll_depth')->insert([
                'session' => 'ux-test-'.$i, 'userid' => null, 'max_position' => $max, 'items_available' => $avail,
                'context' => $ctx, 'created_at' => self::DAY.' 12:00:00',
            ]);
        }

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $r = DB::table('ux_search_daily')->where('date', self::DAY)->first();
        $this->assertSame(40, (int) $r->searches);
        $this->assertSame(7, (int) $r->zero_result);
        // Only logged-in users can be linked across searches: user 5's second search is within 60s.
        $this->assertSame(1, (int) $r->re_searched_60s);
        $this->assertSame(3, (int) $r->browse_sessions);
        // Scrolled out = reached the last item (max_position >= items_available - 1).
        $this->assertSame(2, (int) $r->browse_scrolled_out);
    }

    public function test_regret_signals_come_from_mysql_with_their_own_bases(): void
    {
        $poster = $this->createTestUser();
        $other = $this->createTestUser();
        $at = fn (string $t) => self::DAY.' '.$t;
        $post = fn (string $subject, string $arrival, ?int $by = null) => DB::table('messages')->insertGetId([
            'fromuser' => $by ?? $poster->id, 'type' => 'Offer', 'subject' => $subject,
            'textbody' => 'x', 'message' => 'x', 'arrival' => $arrival, 'date' => $arrival, 'source' => 'Platform',
        ]);

        $withdrawn = $post('OFFER: Chair (A)', $at('09:00:00'));
        DB::table('messages_outcomes')->insert(['msgid' => $withdrawn, 'outcome' => 'Withdrawn', 'timestamp' => $at('20:00:00')]);
        $late = $post('OFFER: Table (A)', $at('09:00:00'));
        DB::table('messages_outcomes')->insert(['msgid' => $late, 'outcome' => 'Withdrawn', 'timestamp' => '2026-03-07 12:00:00']);
        $dupA = $post('OFFER: Lamp (B)', $at('10:00:00'));
        $dupB = $post('OFFER: Lamp (B)', $at('10:05:00'));
        $notDup = $post('OFFER: Lamp (B)', $at('11:00:00'));
        $taken = $post('OFFER: Desk (C)', $at('08:00:00'), $other->id);
        DB::table('messages_outcomes')->insert(['msgid' => $taken, 'outcome' => 'Taken', 'timestamp' => $at('12:00:00')]);

        DB::table('messages_promises')->insert(['msgid' => $taken, 'userid' => $poster->id, 'promisedat' => $at('13:00:00')]);
        DB::table('messages_promises')->insert(['msgid' => $dupA, 'userid' => $poster->id, 'promisedat' => $at('13:00:00')]);
        DB::table('messages_reneged')->insert(['msgid' => $taken, 'userid' => $poster->id, 'timestamp' => $at('15:00:00')]);

        $room = DB::table('chat_rooms')->insertGetId(['chattype' => 'User2User', 'user1' => $poster->id, 'user2' => $other->id]);
        $say = fn (int $msg, string $when) => DB::table('chat_messages')->insert([
            'chatid' => $room, 'userid' => $poster->id, 'type' => 'Interested', 'refmsgid' => $msg, 'message' => 'x', 'platform' => 0, 'date' => $when,
        ]);
        $say($taken, $at('14:00:00'));   // after Taken at 12:00: regret
        $say($dupA, $at('14:00:00'));    // not taken: fine
        $say($taken, $at('11:00:00'));   // before Taken: fine

        $this->service()->rollupRegret(Carbon::parse(self::DAY, 'UTC'));

        $get = fn (string $s) => DB::table('ux_regret_daily')->where('date', self::DAY)->where('signal', $s)->first();
        $baseline = DB::table('messages')->whereBetween('arrival', [$at('00:00:00'), $at('23:59:59')])->whereIn('type', ['Offer', 'Wanted'])->count();
        $this->assertSame([1, $baseline], [(int) $get('withdrawn_24h')->count, (int) $get('withdrawn_24h')->base]);
        $this->assertSame([1, $baseline], [(int) $get('duplicate_10m')->count, (int) $get('duplicate_10m')->base]);
        $this->assertSame([1, 2], [(int) $get('promise_reneged')->count, (int) $get('promise_reneged')->base]);
        $this->assertSame([1, 3], [(int) $get('reply_after_taken')->count, (int) $get('reply_after_taken')->base]);
    }

    public function test_health_compares_flow_starts_with_entry_page_views_and_events_with_their_median(): void
    {
        $d = $this->dims();
        $this->lokiMatrix($this->flowPattern('give', 'flow_start'), [[$d, 80]]);
        // The entry pages only, anchored. Unanchored, "give|give-mobile" matched every page of the
        // offer flow on live (2745 views on 13 October, against 732 of the entry page), so the
        // ratio read 0.29 when nothing was wrong.
        $this->lokiMatrix('/event_type="page_view"\} \| json page_route \| page_route=~"\^\(\?:give\|give-mobile\)\$" \[1d\]/', [[[], 100]]);
        $this->lokiMatrix('/sum\(count_over_time\(\{[^}]*event_type="page_view"\} \[1d\]/', [[[], 1000]]);
        $this->lokiMatrix('/sum\(count_over_time\(\{[^}]*event_type="interaction"\} \[1d\]/', [[[], 50]]);
        foreach ([1000, 1000, 1000, 1200, 800, 1000, 1000] as $i => $n) {
            DB::table('ux_telemetry_health_daily')->insert(['date' => Carbon::parse(self::DAY)->subDays($i + 1)->toDateString(), 'metric' => 'event:page_view', 'observed' => $n]);
        }
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-04', 'metric' => 'event:interaction', 'observed' => 100]);
        DB::table('ux_telemetry_health_daily')->insert(['date' => '2026-03-03', 'metric' => 'event:interaction', 'observed' => 100]);

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $m = fn (string $k) => DB::table('ux_telemetry_health_daily')->where('date', self::DAY)->where('metric', $k)->first();
        $this->assertEquals([100, 80, 0.8], [$m('flow_start:give')->expected, $m('flow_start:give')->observed, $m('flow_start:give')->ratio]);
        $this->assertEquals([1000, 1000, 1.0], [$m('event:page_view')->expected, $m('event:page_view')->observed, $m('event:page_view')->ratio]);
        // Two days of history is not enough for a baseline.
        $this->assertNull($m('event:interaction')->expected);
        $this->assertNull($m('event:interaction')->ratio);
        $this->assertEquals(50, $m('event:interaction')->observed);
    }

    private function healthRow(string $metric, string $date, float $observed): void
    {
        DB::table('ux_telemetry_health_daily')->insert(['date' => $date, 'metric' => $metric, 'observed' => $observed]);
    }

    private function healthFor(string $metric)
    {
        return DB::table('ux_telemetry_health_daily')->where('date', self::DAY)->where('metric', $metric)->first();
    }

    public function test_health_baseline_ignores_days_before_a_change_in_sampling(): void
    {
        // Impressions went to 10% of sessions: a week at full rate, then a week sampled. The
        // median of the mixed fortnight is 5500, so a normal sampled day read as 0.18.
        config()->set('freegle.ux.health_change_points', ['event:impressions' => '2026-02-26']);
        $this->lokiMatrix('/sum\(count_over_time\(\{[^}]*event_type="impressions"\} \[1d\]/', [[[], 1000]]);
        for ($i = 1; $i <= 14; $i++) {
            $date = Carbon::parse(self::DAY)->subDays($i)->toDateString();
            $this->healthRow('event:impressions', $date, $date >= '2026-02-26' ? 1000 : 10000);
        }

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $this->assertEquals([1000, 1.0], [$this->healthFor('event:impressions')->expected, $this->healthFor('event:impressions')->ratio]);
    }

    public function test_health_says_not_enough_history_instead_of_comparing_against_a_mostly_empty_past(): void
    {
        // event:flow only started to arrive recently: five real days among empty ones. A median
        // over that is nonsense (it read 3.4x), and five days is under the minimum anyway.
        $this->lokiMatrix('/sum\(count_over_time\(\{[^}]*event_type="flow"\} \[1d\]/', [[[], 500]]);
        $this->healthRow('event:flow', '2026-03-04', 10);
        $this->healthRow('event:flow', '2026-03-03', 20);
        $this->healthRow('event:flow', '2026-03-02', 30);
        $this->healthRow('event:flow', '2026-03-01', 40);
        $this->healthRow('event:flow', '2026-02-28', 50);
        $this->healthRow('event:flow', '2026-02-27', 0);
        $this->healthRow('event:flow', '2026-02-26', 0);

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $row = $this->healthFor('event:flow');
        $this->assertNull($row->expected);
        $this->assertNull($row->ratio);
        $this->assertEquals(500, $row->observed);
    }

    public function test_health_baseline_skips_empty_days_once_there_are_enough_real_ones(): void
    {
        $this->lokiMatrix('/sum\(count_over_time\(\{[^}]*event_type="session_start"\} \[1d\]/', [[[], 100]]);
        foreach (range(1, 8) as $i) {
            $this->healthRow('event:session_start', Carbon::parse(self::DAY)->subDays($i)->toDateString(), 100);
        }
        foreach (range(9, 14) as $i) {
            $this->healthRow('event:session_start', Carbon::parse(self::DAY)->subDays($i)->toDateString(), 0);
        }

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $this->assertEquals([100, 1.0], [$this->healthFor('event:session_start')->expected, $this->healthFor('event:session_start')->ratio]);
    }

    public function test_sample_sessions_are_capped_per_step_and_old_ones_deleted(): void
    {
        config()->set('freegle.ux.sample_per_step', 2);
        $lines = [];
        foreach (['s1', 's2', 's3', 's1'] as $s) {
            $lines[] = ['session_id' => $s, 'last_step' => 'give-mobile-photos', 'flow' => 'give'];
        }
        $lines[] = ['session_id' => 's9', 'last_step' => 'give-mobile-details', 'flow' => 'give'];
        $this->lokiLines('/flow="give" \| action_name="flow_abandon"$/', $lines);
        DB::table('ux_sample_sessions')->insert(['date' => '2026-02-20', 'flow' => 'give', 'step' => 'x', 'signal' => 'abandon', 'session_id' => 'old']);

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $photos = DB::table('ux_sample_sessions')->where('date', self::DAY)->where('step', 'give-mobile-photos')->pluck('session_id')->all();
        $this->assertSame(['s1', 's2'], $photos);
        $this->assertSame(1, DB::table('ux_sample_sessions')->where('date', self::DAY)->where('step', 'give-mobile-details')->count());
        $this->assertSame('abandon', DB::table('ux_sample_sessions')->where('date', self::DAY)->value('signal'));
        $this->assertSame(0, DB::table('ux_sample_sessions')->where('session_id', 'old')->count());
    }

    public function test_rerunning_a_day_replaces_it_and_leaves_other_days(): void
    {
        DB::table('ux_flow_daily')->insert(['date' => self::DAY, 'flow' => 'give', 'step' => 'stale', 'entered' => 99]);
        DB::table('ux_flow_daily')->insert(['date' => '2026-03-04', 'flow' => 'give', 'step' => 'other-day', 'entered' => 1]);
        $this->lokiMatrix($this->flowPattern('give', 'step_enter'), [[$this->dims(['step' => 'fresh', 'first_visit' => 'true']), 3]]);

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));
        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        $this->assertSame(['fresh'], DB::table('ux_flow_daily')->where('date', self::DAY)->pluck('step')->all());
        $this->assertSame(1, DB::table('ux_flow_daily')->where('date', '2026-03-04')->count());
    }

    public function test_loki_failure_writes_no_loki_tables_and_keeps_the_old_rows(): void
    {
        DB::table('ux_flow_daily')->insert(['date' => self::DAY, 'flow' => 'give', 'step' => 'old', 'entered' => 5]);
        $this->lokiDown = true;

        $this->assertFalse($this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC')));

        $this->assertSame(['old'], DB::table('ux_flow_daily')->where('date', self::DAY)->pluck('step')->all());
        $this->assertSame(0, DB::table('ux_telemetry_health_daily')->where('date', self::DAY)->count());
    }

    private const SERIES_LIMIT = "maximum of series (500) reached for a single query";

    public function test_a_series_limit_error_is_split_by_page_route_not_treated_as_loki_down(): void
    {
        Log::spy();
        $k = fn (string $page, string $c) => ["page_route" => $page, "component" => $c, "device_class" => "desktop"];
        // Grouped by page_route AND component: too many series. Real Loki answers 400.
        $this->lokiError("/sum by \(page_route, component, device_class\) \(count_over_time\(.*interaction_type=\"click\" \| device_class=\"desktop\"\s*\[1d\]/", 400, self::SERIES_LIMIT);
        // The list of routes is small.
        $this->lokiMatrix("/sum by \(page_route\) \(count_over_time\(.*interaction_type=\"click\"/", [[["page_route" => "browse-term"], 9], [["page_route" => "give"], 3]]);
        // Per route the answer fits.
        $this->lokiMatrix("/sum by \(component, device_class\) \(count_over_time\(.*device_class=\"desktop\" \| page_route=\"browse-term\"\s*\[1d\]/", [[["component" => "PostCard", "device_class" => "desktop"], 8]]);
        $this->lokiMatrix("/sum by \(component, device_class\) \(count_over_time\(.*device_class=\"desktop\" \| page_route=\"give\"\s*\[1d\]/", [[["component" => "Next", "device_class" => "desktop"], 3]]);

        $this->assertTrue($this->service()->rollupLokiDay(Carbon::parse(self::DAY, "UTC")));

        $this->assertSame(8, (int) DB::table("ux_component_daily")->where("date", self::DAY)->where("page", "browse-term")->where("component", "PostCard")->value("clicks"));
        $this->assertSame(3, (int) DB::table("ux_component_daily")->where("date", self::DAY)->where("page", "give")->value("clicks"));
        Log::shouldHaveReceived("warning")->withArgs(fn ($m) => str_contains($m, "series limit"))->atLeast()->once();
    }

    public function test_a_series_limit_on_a_flow_query_skips_that_query_only(): void
    {
        $this->lokiError($this->flowPattern("login", "step_enter"), 400, self::SERIES_LIMIT);
        $this->lokiMatrix($this->flowPattern("give", "step_enter"), [[$this->dims(["step" => "s", "first_visit" => "true"]), 4]]);

        $this->assertTrue($this->service()->rollupLokiDay(Carbon::parse(self::DAY, "UTC")));
        $this->assertSame(4, (int) DB::table("ux_flow_daily")->where("date", self::DAY)->where("flow", "give")->value("entered"));
    }

    public function test_a_server_error_is_still_loki_unavailable(): void
    {
        $this->lokiError("/step_enter/", 500, "boom");
        $this->assertFalse($this->service()->rollupLokiDay(Carbon::parse(self::DAY, "UTC")));
    }

    public function test_a_client_error_that_is_not_the_series_limit_aborts_the_day(): void
    {
        foreach ([[400, "parse error at line 1, col 12: syntax error"], [429, "too many outstanding requests"]] as [$status, $body]) {
            DB::table("ux_flow_daily")->where("date", self::DAY)->delete();
            DB::table("ux_flow_daily")->insert(["date" => self::DAY, "flow" => "give", "step" => "old", "entered" => 5]);
            $this->lokiError($this->flowPattern("login", "step_enter"), $status, $body);

            $this->assertFalse($this->service()->rollupLokiDay(Carbon::parse(self::DAY, "UTC")), "HTTP $status");
            $this->assertSame(["old"], DB::table("ux_flow_daily")->where("date", self::DAY)->pluck("step")->all(), "HTTP $status");
        }
    }

    public function test_no_metric_query_uses_a_bare_json_parser(): void
    {
        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, "UTC"));

        $metric = array_filter($this->lokiQueries, fn ($q) => str_contains($q, "count_over_time") || str_contains($q, "quantile_over_time"));
        $this->assertNotEmpty($metric);
        foreach ($metric as $q) {
            if (str_contains($q, "| json")) {
                $this->assertDoesNotMatchRegularExpression("/\| json\s*(\||\[|\)|$)/", $q, "bare | json in: ".$q);
            }
        }
    }

    public function test_reply_has_no_flow_start_health_metric(): void
    {
        config()->set("freegle.ux.flow_entry_routes", ["give" => ["give"], "reply" => ["message-id"]]);
        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, "UTC"));

        $this->assertSame(1, DB::table("ux_telemetry_health_daily")->where("date", self::DAY)->where("metric", "flow_start:give")->count());
        $this->assertSame(0, DB::table("ux_telemetry_health_daily")->where("date", self::DAY)->where("metric", "flow_start:reply")->count());
    }

    public function test_settings_has_no_flow_start_health_metric(): void
    {
        // A settings flow starts at the first save in a visit, so a visit that only looks sends
        // nothing. Its starts against settings page views measure how many visits saved, not
        // whether events were lost.
        config()->set("freegle.ux.flow_entry_routes", ["give" => ["give"], "settings" => ["settings"]]);
        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, "UTC"));

        $this->assertSame(1, DB::table("ux_telemetry_health_daily")->where("date", self::DAY)->where("metric", "flow_start:give")->count());
        $this->assertSame(0, DB::table("ux_telemetry_health_daily")->where("date", self::DAY)->where("metric", "flow_start:settings")->count());
    }

    public function test_raw_line_paging_splits_a_window_that_returns_the_limit(): void
    {
        $big = array_fill(0, 1000, ['page_route' => 'x', 'device_class' => 'desktop', 'components' => ['A' => 1]]);
        $calls = 0;
        array_unshift($this->lokiRules, ['/^\{[^}]*event_type="impressions"\}$/', function ($q) use ($big, &$calls) {
            $calls++;
            // Pages stay small enough for Loki's 4MB internal message limit.
            $this->assertSame('1000', (string) $q['limit']);
            $span = ((int) $q['end'] - (int) $q['start']) / 1_000_000_000;
            // The whole day is "too many"; any narrower window fits.
            $lines = $span >= 86400 ? $big : [['page_route' => 'x', 'device_class' => 'desktop', 'components' => ['A' => 1]]];
            $values = [];
            foreach ($lines as $i => $l) {
                $values[] = [(string) $i, json_encode($l)];
            }

            return ['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [['stream' => [], 'values' => $values]]]];
        }]);

        $this->service()->rollupLokiDay(Carbon::parse(self::DAY, 'UTC'));

        // Two halves of a day, not one capped page.
        $this->assertSame(2, (int) DB::table('ux_component_daily')->where('date', self::DAY)->where('component', 'A')->value('impressions'));
        $this->assertSame(3, $calls);
    }
}
