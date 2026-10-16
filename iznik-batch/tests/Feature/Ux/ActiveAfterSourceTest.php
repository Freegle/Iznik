<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\Sources\ActiveAfterSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A notification counted by a visit, over real rows. The time is 12:00 on 21 October and the window is
 * a day, so an exposure is mature if it is at or before 12:00 on 20 October.
 */
class ActiveAfterSourceTest extends TestCase
{
    private const NAME = 'active-test';

    private ActiveAfterSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = new ActiveAfterSource;
        DB::table('ux_experiment_exposures')->where('experiment', 'like', 'active-%')->delete();
    }

    private function now(): Carbon
    {
        return Carbon::parse('2026-10-21 12:00:00', 'UTC');
    }

    /** @param array<string, mixed> $design */
    private function exp(array $design = []): object
    {
        return (object) [
            'name' => self::NAME,
            'arms' => json_encode(['control' => [0, 49], 'items' => [50, 99]]),
            'design' => json_encode($design + ['channel' => 'push', 'type' => 'daily_new_posts', 'source' => 'active_after', 'window_hours' => 24]),
            'started_at' => '2026-10-20 00:00:00',
        ];
    }

    private function exposure(int $userId, string $arm, string $at, string $type = 'push', int $subjectId = 0): void
    {
        DB::table('ux_experiment_exposures')->insert([
            'experiment' => self::NAME, 'arm' => $arm, 'userid' => $userId, 'subject_type' => $type,
            'subject_id' => $subjectId ?: intdiv(Carbon::parse($at, 'UTC')->getTimestamp(), 86400), 'stratum' => null, 'exposed_at' => $at, 'detail' => null,
        ]);
    }

    private function active(int $userId, string $hour): void
    {
        DB::table('users_active')->insert(['userid' => $userId, 'timestamp' => $hour]);
    }

    private function counts(array $design = []): array
    {
        return $this->source->counts($this->exp($design), $this->now(), null);
    }

    // ---------------------------------------------------------------- a visit after the notification

    public function test_the_hour_it_was_sent_in_counts_and_the_hour_before_does_not(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();
        $this->exposure($a->id, 'control', '2026-10-20 08:30:00');
        $this->exposure($b->id, 'control', '2026-10-20 08:30:00');
        $this->active($a->id, '2026-10-20 08:00:00');   // the hour it went out in
        $this->active($b->id, '2026-10-20 07:00:00');   // the hour before

        $c = $this->counts();

        $this->assertSame(2, $c['arms']['control']['starts']);
        $this->assertSame(1, $c['arms']['control']['completes']);
    }

    public function test_a_visit_inside_the_window_counts_and_one_after_it_does_not(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();
        $c = $this->createTestUser();
        $this->exposure($a->id, 'items', '2026-10-20 08:30:00');
        $this->exposure($b->id, 'items', '2026-10-20 08:30:00');
        $this->exposure($c->id, 'items', '2026-10-20 08:30:00');
        $this->active($a->id, '2026-10-21 08:00:00');   // before 08:30 the next day: inside
        $this->active($b->id, '2026-10-21 09:00:00');   // after it: outside
        // $c never came back.

        $counts = $this->counts();

        $this->assertSame(3, $counts['arms']['items']['starts']);
        $this->assertSame(1, $counts['arms']['items']['completes']);
    }

    public function test_another_members_visit_is_not_yours(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();
        $this->exposure($a->id, 'control', '2026-10-20 08:30:00');
        $this->active($b->id, '2026-10-20 09:00:00');

        $this->assertSame(0, $this->counts()['arms']['control']['completes']);
    }

    public function test_a_member_sent_a_notification_young_is_not_counted_yet(): void
    {
        $a = $this->createTestUser();
        $this->exposure($a->id, 'control', '2026-10-21 06:30:00');
        $this->active($a->id, '2026-10-21 07:00:00');

        $c = $this->counts();

        $this->assertSame(0, $c['arms']['control']['starts']);
        $this->assertSame(1, $c['extra']['exposed']['control']);
    }

    public function test_a_member_notified_by_email_then_by_push_is_one_count_on_the_first(): void
    {
        $a = $this->createTestUser();
        $this->exposure($a->id, 'control', '2026-10-20 06:00:00', 'email', 100);
        $this->exposure($a->id, 'control', '2026-10-20 08:00:00', 'push');
        $this->active($a->id, '2026-10-20 07:00:00');    // inside the window of the email, before the push

        $c = $this->counts();

        $this->assertSame(1, $c['arms']['control']['starts']);
        $this->assertSame(1, $c['arms']['control']['completes'], 'the visit is judged from the first notification');
    }

    public function test_a_push_a_bell_and_an_email_are_all_counted_and_another_type_is_not(): void
    {
        $users = [$this->createTestUser(), $this->createTestUser(), $this->createTestUser(), $this->createTestUser()];
        $this->exposure($users[0]->id, 'control', '2026-10-20 08:30:00', 'push');
        $this->exposure($users[1]->id, 'control', '2026-10-20 08:30:00', 'bell', 11);
        $this->exposure($users[2]->id, 'control', '2026-10-20 08:30:00', 'email', 12);
        $this->exposure($users[3]->id, 'control', '2026-10-20 08:30:00', 'chat', 13);

        $this->assertSame(3, $this->counts()['arms']['control']['starts']);
    }

    // ---------------------------------------------------------------- the push guardrail

    private function settings(int $userId, ?string $settings): void
    {
        DB::table('users')->where('id', $userId)->update(['settings' => $settings]);
    }

    public function test_turning_the_push_off_is_the_guardrail_for_a_push(): void
    {
        $off = $this->createTestUser();
        $on = $this->createTestUser();
        $unset = $this->createTestUser();
        $broken = $this->createTestUser();
        $this->settings($off->id, json_encode(['notifications' => ['dailypostspush' => false]]));
        $this->settings($on->id, json_encode(['notifications' => ['dailypostspush' => true]]));
        $this->settings($unset->id, json_encode(['notifications' => []]));
        $this->settings($broken->id, 'this is not json');
        foreach ([$off, $on, $unset, $broken] as $u) {
            $this->exposure($u->id, 'items', '2026-10-20 08:30:00');
        }

        $guards = $this->counts()['guards'];

        $this->assertSame(['push_opt_out'], array_keys($guards));
        $this->assertSame(['bad' => 1, 'base' => 4], $guards['push_opt_out']['items'], 'one of four; text that is not JSON is not "off", and does not stop the read');
        $this->assertSame(['bad' => 0, 'base' => 0], $guards['push_opt_out']['control']);
    }

    public function test_a_member_who_no_longer_has_an_account_is_still_counted(): void
    {
        $this->exposure(2147000001, 'control', '2026-10-20 08:30:00');

        $this->assertSame(1, $this->counts()['arms']['control']['starts']);
    }

    public function test_there_is_no_guardrail_for_an_email_or_server_code(): void
    {
        $a = $this->createTestUser();
        $this->settings($a->id, json_encode(['notifications' => ['dailypostspush' => false]]));
        $this->exposure($a->id, 'control', '2026-10-20 08:30:00', 'email', 5);

        foreach (['email', 'server'] as $channel) {
            $design = ['channel' => $channel];
            $this->assertSame([], $this->source->guardrails($this->exp($design)));
            $this->assertSame([], $this->counts($design)['guards']);
        }
    }

    public function test_the_push_guardrail_has_its_own_small_margin(): void
    {
        $g = $this->source->guardrails($this->exp());

        $this->assertSame(['push_opt_out'], array_keys($g));
        $this->assertSame(0.3, $g['push_opt_out']['margin_points']);
        $this->assertSame('the share of members who turned the push off', $g['push_opt_out']['label']);
    }

    // ---------------------------------------------------------------- the rest

    /** @return array<string, array{0: int, 1: string}> */
    public static function windows(): array
    {
        return ['a day' => [24, 'a day'], 'a week' => [168, '7 days'], 'six hours' => [6, '6 hours']];
    }

    #[DataProvider('windows')]
    public function test_the_words_name_the_window(int $hours, string $text): void
    {
        $words = $this->source->words($this->exp(['window_hours' => $hours]));

        $this->assertSame('were counted', $words['started']);
        $this->assertSame("were active within $text", $words['finished']);
        $this->assertSame('Counted', $words['tab_started']);
        $this->assertSame("Active within $text", $words['tab_finished']);
        $this->assertStringContainsString("only when that was at least $text ago", $words['note']);
        $this->assertStringContainsString('The hour it was sent counts.', $words['note']);
    }

    public function test_the_note_says_what_active_means_because_nothing_else_on_the_tab_does(): void
    {
        // users_active is written when the site or app loads the member's notifications, which it does when
        // they have something unread or open the bell. It is not written for every visit.
        $note = $this->source->words($this->exp())['note'];

        $this->assertStringStartsWith("Active means the site or app loaded the member's notifications, which it does when they have something unread or open the bell.", $note);
    }

    public function test_there_is_no_volume_to_size_a_push_from_before_the_first_run(): void
    {
        $this->assertNull($this->source->volume($this->exp(), $this->now()));
    }

    public function test_every_arm_is_present_with_zeros_when_nothing_was_sent(): void
    {
        $c = $this->counts();

        $this->assertSame(['starts' => 0, 'completes' => 0, 'strata' => []], $c['arms']['control']);
        $this->assertSame(['control' => 0, 'items' => 0], $c['extra']['counted']);
    }
}
