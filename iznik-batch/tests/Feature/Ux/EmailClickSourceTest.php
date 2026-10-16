<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\Sources\EmailClickSource;
use App\Services\Ux\UxArm;
use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * Mail counted by a click, over real rows. The time is 12:00 on 21 October and the window is a day, so
 * an exposure is mature if it is at or before 12:00 on 20 October. The experiment started at midnight
 * on the 20th.
 */
class EmailClickSourceTest extends TestCase
{
    use FakesUxLoki;

    private const NAME = 'click-test';

    private EmailClickSource $source;

    /** @var array<int, int> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->fakeLoki();
        $this->source = new EmailClickSource;
        DB::table('ux_experiment_exposures')->where('experiment', 'like', 'click-%')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function now(): Carbon
    {
        return Carbon::parse('2026-10-21 12:00:00', 'UTC');
    }

    /** @param array<string, mixed> $design */
    private function exp(array $design = [], string $started = '2026-10-20 00:00:00'): object
    {
        return (object) [
            'name' => self::NAME,
            'arms' => json_encode(['control' => [0, 49], 'items' => [50, 99]]),
            'design' => json_encode($design + ['channel' => 'email', 'type' => 'ClickTestDigest', 'source' => 'email_click', 'window_hours' => 24]),
            'started_at' => $started,
        ];
    }

    /**
     * A small number stands for a real member, made on first use, because email_tracking.userid is a
     * foreign key to users. A large id or null is used as it is.
     */
    private function member(?int $n): ?int
    {
        if ($n === null || $n >= 1000000) {
            return $n;
        }

        return $this->members[$n] ??= $this->createTestUser()->id;
    }

    private function tracking(?int $userId, ?string $clicked = null, ?string $unsubscribed = null, string $sent = '2026-10-20 08:00:00', string $type = 'ClickTestDigest'): int
    {
        return DB::table('email_tracking')->insertGetId([
            'tracking_id' => bin2hex(random_bytes(16)), 'email_type' => $type, 'userid' => $this->member($userId),
            'recipient_email' => 'u'.bin2hex(random_bytes(4)).'@test.invalid', 'sent_at' => $sent, 'clicked_at' => $clicked, 'unsubscribed_at' => $unsubscribed,
            'created_at' => $sent, 'updated_at' => $sent,
        ]);
    }

    private function exposure(int $userId, string $arm, int $subjectId, string $at, string $type = 'email', ?string $stratum = null, string $experiment = self::NAME): int
    {
        return DB::table('ux_experiment_exposures')->insertGetId([
            'experiment' => $experiment, 'arm' => $arm, 'userid' => $this->member($userId), 'subject_type' => $type, 'subject_id' => $subjectId,
            'stratum' => $stratum, 'exposed_at' => $at, 'detail' => null,
        ]);
    }

    /** A mature exposure with a tracking row that has the given click and unsubscribe times. */
    private function sent(int $userId, string $arm, ?string $clicked = null, ?string $unsubscribed = null, string $at = '2026-10-20 08:00:00'): int
    {
        $t = $this->tracking($userId, $clicked, $unsubscribed, $at);
        $this->exposure($userId, $arm, $t, $at);

        return $t;
    }

    private function counts(?Carbon $since = null, array $design = []): array
    {
        return $this->source->counts($this->exp($design), $this->now(), $since);
    }

    // ---------------------------------------------------------------- who is counted

    public function test_a_click_within_the_window_converts_and_nothing_else_does(): void
    {
        $this->sent(1, 'control', '2026-10-20 09:00:00');
        $this->sent(2, 'control');
        $this->sent(3, 'items', '2026-10-20 08:30:00');
        $this->sent(4, 'items', '2026-10-20 09:30:00');
        $this->sent(5, 'items');

        $c = $this->counts();

        $this->assertSame(2, $c['arms']['control']['starts']);
        $this->assertSame(1, $c['arms']['control']['completes']);
        $this->assertSame(3, $c['arms']['items']['starts']);
        $this->assertSame(2, $c['arms']['items']['completes']);
    }

    public function test_a_click_after_the_window_is_no_click(): void
    {
        $this->sent(1, 'control', '2026-10-21 08:00:01');      // a day and a second after 08:00 on the 20th
        $this->sent(2, 'control', '2026-10-21 07:59:59');      // a second inside the window

        $c = $this->counts();

        $this->assertSame(2, $c['arms']['control']['starts']);
        $this->assertSame(1, $c['arms']['control']['completes']);
    }

    public function test_an_unsubscribe_click_is_no_click_and_counts_against_the_guardrail(): void
    {
        // The unsubscribe link also sets clicked_at.
        $this->sent(1, 'control', '2026-10-20 09:00:00', '2026-10-20 09:00:00');
        $this->sent(2, 'items', '2026-10-20 09:00:00', '2026-10-20 09:00:00');
        $this->sent(3, 'items', '2026-10-20 09:00:00');

        $c = $this->counts();

        $this->assertSame(0, $c['arms']['control']['completes']);
        $this->assertSame(1, $c['arms']['items']['completes'], 'only the member who clicked a real link');
        $this->assertSame(['bad' => 1, 'base' => 1], $c['guards']['unsubscribe']['control']);
        $this->assertSame(['bad' => 1, 'base' => 2], $c['guards']['unsubscribe']['items']);
    }

    public function test_an_unsubscribe_after_the_window_is_not_counted_against_the_guardrail(): void
    {
        $this->sent(1, 'control', null, '2026-10-21 08:00:01');

        $this->assertSame(['bad' => 0, 'base' => 1], $this->counts()['guards']['unsubscribe']['control']);
    }

    public function test_a_member_is_counted_once_on_the_first_email_they_were_sent(): void
    {
        // Member 1 clicked the second email but not the first. Member 2 clicked the first and not the second.
        $this->sent(1, 'control', null, null, '2026-10-20 06:00:00');
        $this->sent(1, 'control', '2026-10-20 10:00:00', null, '2026-10-20 08:00:00');
        $this->sent(2, 'items', '2026-10-20 07:00:00', null, '2026-10-20 06:00:00');
        $this->sent(2, 'items', null, null, '2026-10-20 08:00:00');

        $c = $this->counts();

        $this->assertSame(1, $c['arms']['control']['starts']);
        $this->assertSame(0, $c['arms']['control']['completes'], 'counted on the first email, which was not clicked');
        $this->assertSame(1, $c['arms']['items']['starts']);
        $this->assertSame(1, $c['arms']['items']['completes']);
    }

    public function test_a_first_exposure_that_is_too_young_is_not_counted_and_a_later_one_does_not_take_its_place(): void
    {
        // The first row written is only 6 hours old. The second was written later but carries an older
        // time (two writers with clocks that disagree). The member is not counted yet.
        $young = $this->tracking(1, '2026-10-21 08:00:00', null, '2026-10-21 06:00:00');
        $this->exposure(1, 'control', $young, '2026-10-21 06:00:00');
        $old = $this->tracking(1, '2026-10-20 08:00:00', null, '2026-10-20 06:00:00');
        $this->exposure(1, 'control', $old, '2026-10-20 06:00:00');

        $c = $this->counts();

        $this->assertSame(0, $c['arms']['control']['starts']);
        $this->assertSame(0, $c['arms']['control']['completes']);
        $this->assertSame(1, $c['extra']['exposed']['control'], 'but they are in the progress count');
        $this->assertSame(0, $c['extra']['counted']['control']);
    }

    public function test_a_mature_first_exposure_counts_even_when_a_later_one_is_young(): void
    {
        $this->sent(1, 'control', '2026-10-20 09:00:00', null, '2026-10-20 08:00:00');
        $this->sent(1, 'control', null, null, '2026-10-21 10:00:00');

        $c = $this->counts();

        $this->assertSame(1, $c['arms']['control']['starts']);
        $this->assertSame(1, $c['arms']['control']['completes']);
    }

    public function test_a_push_is_not_an_email_so_only_the_email_is_counted(): void
    {
        $this->exposure(1, 'control', 20260, '2026-10-20 06:00:00', 'push');
        $this->sent(1, 'control', '2026-10-20 09:00:00', null, '2026-10-20 08:00:00');
        $this->exposure(2, 'items', 20260, '2026-10-20 06:00:00', 'push');

        $c = $this->counts();

        $this->assertSame(1, $c['arms']['control']['starts'], 'the member is counted on the email, whatever else they were sent');
        $this->assertSame(1, $c['arms']['control']['completes']);
        $this->assertSame(0, $c['arms']['items']['starts'], 'a member sent only a push is not in an email experiment');
    }

    public function test_exposures_before_the_start_are_not_counted(): void
    {
        $this->sent(1, 'control', '2026-10-19 09:00:00', null, '2026-10-19 08:00:00');          // before the start
        $this->sent(2, 'control', null, null, '2026-10-19 08:00:00');                           // before the start
        $this->sent(2, 'control', '2026-10-20 09:00:00', null, '2026-10-20 08:00:00');          // the first since the start

        $c = $this->counts();

        $this->assertSame(1, $c['arms']['control']['starts']);
        $this->assertSame(1, $c['arms']['control']['completes']);
    }

    public function test_a_holdout_look_counts_first_exposures_from_the_decision(): void
    {
        $this->sent(1, 'control', null, null, '2026-10-20 06:00:00');
        $this->sent(2, 'control', '2026-10-20 09:00:00', null, '2026-10-20 08:00:00');

        $c = $this->counts(Carbon::parse('2026-10-20 07:00:00', 'UTC'));

        $this->assertSame(1, $c['arms']['control']['starts'], 'only the exposure since the decision');
    }

    public function test_another_experiments_rows_are_not_ours(): void
    {
        $t = $this->tracking(1, '2026-10-20 09:00:00');
        $this->exposure(1, 'control', $t, '2026-10-20 08:00:00', 'email', null, 'click-other');

        $this->assertSame(0, $this->counts()['arms']['control']['starts']);
    }

    public function test_an_arm_the_experiment_does_not_have_is_ignored(): void
    {
        $this->sent(1, 'retired', '2026-10-20 09:00:00');

        $c = $this->counts();

        $this->assertSame(['control', 'items'], array_keys($c['arms']));
    }

    public function test_a_tracking_row_that_has_been_purged_is_counted_but_is_no_click(): void
    {
        $this->exposure(1, 'control', 987654321, '2026-10-20 08:00:00');

        $c = $this->counts();

        $this->assertSame(1, $c['arms']['control']['starts']);
        $this->assertSame(0, $c['arms']['control']['completes']);
    }

    public function test_every_arm_is_present_with_zeros_when_nothing_was_sent(): void
    {
        $c = $this->counts();

        $this->assertSame(['starts' => 0, 'completes' => 0, 'strata' => []], $c['arms']['control']);
        $this->assertSame(['starts' => 0, 'completes' => 0, 'strata' => []], $c['arms']['items']);
        $this->assertSame(['bad' => 0, 'base' => 0], $c['guards']['unsubscribe']['items']);
        $this->assertSame(['exposed' => ['control' => 0, 'items' => 0], 'counted' => ['control' => 0, 'items' => 0]], $c['extra']);
    }

    public function test_strata_are_the_exposures_own_and_a_null_stratum_is_one_pool(): void
    {
        foreach ([[1, 'in', '2026-10-20 09:00:00'], [2, 'in', null], [3, 'out', null], [4, null, '2026-10-20 09:00:00']] as [$id, $stratum, $click]) {
            $t = $this->tracking($id, $click);
            $this->exposure($id, 'control', $t, '2026-10-20 08:00:00', 'email', $stratum);
        }

        $strata = $this->counts()['arms']['control']['strata'];

        $this->assertEquals([
            'in' => ['starts' => 2, 'completes' => 1],
            'out' => ['starts' => 1, 'completes' => 0],
            'all' => ['starts' => 1, 'completes' => 1],
        ], $strata);
    }

    public function test_progress_shows_everyone_exposed_and_the_count_only_those_who_have_matured(): void
    {
        $this->sent(1, 'control');                                          // mature
        $this->sent(2, 'control', null, null, '2026-10-21 10:00:00');      // 2 hours old
        $this->sent(3, 'items', null, null, '2026-10-21 11:00:00');        // 1 hour old

        $extra = $this->counts()['extra'];

        $this->assertSame(['control' => 2, 'items' => 1], $extra['exposed']);
        $this->assertSame(['control' => 1, 'items' => 0], $extra['counted']);
    }

    // ---------------------------------------------------------------- the window and the words

    public function test_a_longer_window_waits_longer_to_count_a_member(): void
    {
        $this->sent(1, 'control', '2026-10-20 09:00:00', null, '2026-10-20 08:00:00');   // 28 hours old

        $this->assertSame(1, $this->counts(null, ['window_hours' => 24])['arms']['control']['starts']);
        $this->assertSame(0, $this->counts(null, ['window_hours' => 48])['arms']['control']['starts'], 'a 48 hour window has not run out yet');
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function windows(): array
    {
        return ['a day' => [24, 'a day'], 'a week' => [168, '7 days'], 'an hour' => [1, '1 hour'], 'a day and a half' => [36, '36 hours']];
    }

    #[DataProvider('windows')]
    public function test_the_words_name_the_window(int $hours, string $text): void
    {
        $words = $this->source->words($this->exp(['window_hours' => $hours]));

        $this->assertSame('members', $words['who']);
        // The number is the members counted, who are fewer than the members sent: "Exposed" is the only word for sent.
        $this->assertSame('were counted', $words['started']);
        $this->assertSame("clicked a link within $text", $words['finished']);
        $this->assertSame('Counted', $words['tab_started']);
        $this->assertSame("Clicked within $text", $words['tab_finished']);
        $this->assertSame("Each member is counted once, for the first email they were sent, and only when that was at least $text ago.", $words['note']);
    }

    public function test_the_guardrail_is_unsubscribing_with_its_own_small_margin(): void
    {
        $g = $this->source->guardrails($this->exp());

        $this->assertSame(['unsubscribe'], array_keys($g));
        $this->assertSame(0.3, $g['unsubscribe']['margin_points']);
        $this->assertSame('the share of members who unsubscribed', $g['unsubscribe']['label']);
    }

    // ---------------------------------------------------------------- volume

    public function test_volume_is_the_recipients_of_the_type_over_the_intake_halved_and_their_click_rate(): void
    {
        // The sends that have had the day to be clicked: from 12:00 on the 19th to 12:00 on the 20th.
        $this->tracking(1, '2026-10-19 15:00:00', null, '2026-10-19 14:00:00');
        $this->tracking(2, null, null, '2026-10-19 14:00:00');
        $this->tracking(3, '2026-10-19 16:00:00', '2026-10-19 16:00:00', '2026-10-19 14:00:00');   // an unsubscribe is no click
        $this->tracking(4, null, null, '2026-10-19 14:00:00');
        $this->tracking(5, null, null, '2026-10-19 14:00:00', 'SomeOtherEmail');                       // another type
        $this->tracking(6, null, null, '2026-10-21 08:00:00');                                           // too recent to have had the window
        $this->tracking(7, null, null, '2026-10-18 08:00:00');                                           // before the intake

        $volume = $this->source->volume($this->exp(['intake_hours' => 24]), $this->now());

        $this->assertSame(2, $volume['per_arm'], 'four recipients, halved');
        $this->assertSame(0.25, $volume['rate'], 'one click in four sends');
    }

    public function test_volume_counts_a_member_once_however_many_they_were_sent(): void
    {
        $this->tracking(1, '2026-10-19 15:00:00', null, '2026-10-19 14:00:00');
        $this->tracking(1, null, null, '2026-10-19 18:00:00');
        $this->tracking(2, null, null, '2026-10-19 14:00:00');

        $volume = $this->source->volume($this->exp(['intake_hours' => 24]), $this->now());

        $this->assertSame(1, $volume['per_arm'], 'two recipients, halved');
        $this->assertEqualsWithDelta(1 / 3, $volume['rate'], 0.0001, 'one click in three sends');
    }

    public function test_volume_is_null_with_no_sends_one_recipient_or_no_type(): void
    {
        $this->assertNull($this->source->volume($this->exp(['intake_hours' => 24]), $this->now()), 'no sends');

        $this->tracking(1, null, null, '2026-10-19 14:00:00');
        $this->assertNull($this->source->volume($this->exp(['intake_hours' => 24]), $this->now()), 'one recipient cannot be halved');

        $this->assertNull($this->source->volume($this->exp(['type' => null, 'intake_hours' => 24]), $this->now()), 'no type');
    }

    public function test_volume_with_no_intake_in_the_design_uses_the_channels_default(): void
    {
        $this->tracking(1, null, null, '2026-10-19 14:00:00');
        $this->tracking(2, null, null, '2026-10-19 14:00:00');

        $this->assertSame(1, $this->source->volume($this->exp(), $this->now())['per_arm']);
    }

    // ---------------------------------------------------------------- through the engine

    public function test_an_email_experiment_runs_start_to_decision_over_real_rows(): void
    {
        config()->set('ux_switches', [[
            'name' => self::NAME, 'opened' => '2026-10-21', 'pr' => 'https://github.com/Freegle/Iznik/pull/1', 'keep_until' => null,
            'channel' => 'email', 'type' => 'ClickTestDigest', 'varies' => 'subject', 'arms' => ['control', 'items'],
            'metric' => ['source' => 'email_click', 'window_hours' => 24],
        ]]);
        DB::table('config')->upsert([['key' => UxArm::SWITCH_KEY, 'value' => '1']], ['key'], ['value']);
        foreach (['ux_experiment_reads', 'ux_experiments'] as $t) {
            DB::table($t)->delete();
        }
        $service = app(UxExperimentService::class);
        $id = $service->create([
            'flow' => 'reply', 'name' => self::NAME, 'title' => 'Digest subject', 'hypothesis' => 'A plainer subject gets more clicks.',
            'change' => 'The digest subject names the items.', 'expected' => 'More clicks.', 'arms' => ['control' => [0, 49], 'items' => [50, 99]],
            'design' => ['channel' => 'email', 'type' => 'ClickTestDigest', 'source' => 'email_click', 'window_hours' => 24, 'expected_points' => 3],
        ]);

        $started = $service->start($id);
        $this->assertSame('2026-10-23 12:00:00', $started->ends_at, 'a day of mail and a day to click it');

        // 400 members a side, mailed in the first 20 hours. The change is clicked by 18 percent, the control by 10.
        $mailedAt = '2026-10-21 14:00:00';
        for ($i = 1; $i <= 400; $i++) {
            foreach (['control' => 0, 'items' => 1000] as $arm => $offset) {
                $clicked = ($i % 100) < ($arm === 'items' ? 18 : 10) ? '2026-10-21 14:30:00' : null;
                $t = $this->tracking(null, $clicked, null, $mailedAt);
                $this->exposure(5000000 + $offset + $i, $arm, $t, $mailedAt, 'email', null, self::NAME);
            }
        }

        Carbon::setTestNow('2026-10-23 12:00:00');
        $lines = $service->tick(Carbon::now('UTC'));

        $exp = $service->find($id);
        $this->assertSame('adopted', $exp->status, implode(' | ', $lines).' '.$exp->decision);
        $this->assertStringContainsString('400 members were counted with the change and 400 without; 18.0% clicked a link within a day against 10.0%', $exp->decision);
        $result = json_decode($exp->result, true);
        $this->assertEquals(['control' => 400, 'items' => 400], $result['detail']['counted']);
        $this->assertSame('Counted', $result['words']['tab_started']);
        $this->assertSame([], $this->lokiQueries, 'no Loki call');
    }
}
