<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\Sources\HostingAdClickSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The experiment krystal-hosting-ad counted over real rows. The time is 12:00 on 30 October and the window
 * is 168 hours (7 days), so an exposure is mature if it is at or before 12:00 on 23 October. The run started
 * at midnight on the 16th. An exposure is the decision to show (or not) the hosting ad; a click is the number
 * detail.clicked, the unix time the member pressed the button.
 */
class HostingAdClickSourceTest extends TestCase
{
    private const NAME = 'ha-source-test';

    private const ARMS = ['control' => [0, 49], 'ad' => [50, 99]];

    private HostingAdClickSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->source = new HostingAdClickSource;
        DB::table('ux_experiment_exposures')->where('experiment', 'like', 'ha-%')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exp(): object
    {
        return (object) [
            'name' => self::NAME,
            'arms' => json_encode(self::ARMS),
            'design' => json_encode(['channel' => 'server', 'type' => 'hosting_ad', 'source' => 'hosting_ad_click', 'window_hours' => 168]),
            'started_at' => '2026-10-16 00:00:00',
        ];
    }

    /** A member decided at $at, last seen at $lastaccess, who clicked at $clicked (unix seconds) or not. */
    private function exposed(string $arm, string $at, string $lastaccess, ?int $clicked = null, string $type = 'hosting_ad'): int
    {
        $user = $this->createTestUser(['lastaccess' => $lastaccess]);
        DB::table('ux_experiment_exposures')->insert([
            'experiment' => self::NAME,
            'arm' => $arm,
            'userid' => $user->id,
            'subject_type' => $type,
            'subject_id' => 1,
            'exposed_at' => $at,
            'detail' => $clicked === null ? null : json_encode(['clicked' => $clicked]),
        ]);

        return $user->id;
    }

    public function test_it_names_its_type_words_and_guardrail(): void
    {
        $this->assertSame(['hosting_ad'], $this->source->types());
        $words = $this->source->words($this->exp());
        $this->assertSame('clicked through to Krystal within 7 days', $words['finished']);
        $this->assertStringContainsString('Only the ad arm sees the modal', $words['note']);
        $guards = $this->source->guardrails($this->exp());
        $this->assertSame(2.0, $guards['not_back']['margin_points']);
        $this->assertNull($this->source->volume($this->exp(), Carbon::now()));
    }

    public function test_a_click_inside_the_window_converts_and_one_outside_does_not(): void
    {
        $at = '2026-10-20 10:00:00';
        $inside = Carbon::parse($at)->addDays(3)->getTimestamp();
        $outside = Carbon::parse($at)->addDays(8)->getTimestamp();
        $this->exposed('ad', $at, '2026-10-21 00:00:00', $inside);
        $this->exposed('ad', $at, '2026-10-21 00:00:00', $outside);
        $this->exposed('ad', $at, '2026-10-21 00:00:00', null);
        $this->exposed('control', $at, '2026-10-21 00:00:00', null);

        $counts = $this->source->counts($this->exp(), Carbon::now(), null);

        $this->assertSame(3, $counts['arms']['ad']['starts']);
        $this->assertSame(1, $counts['arms']['ad']['completes']);
        $this->assertSame(1, $counts['arms']['control']['starts']);
        $this->assertSame(0, $counts['arms']['control']['completes']);
        $this->assertEquals(['ad' => 3, 'control' => 1], $counts['extra']['exposed']);
        $this->assertEquals(['ad' => 3, 'control' => 1], $counts['extra']['counted']);
    }

    public function test_an_exposure_younger_than_the_window_is_exposed_but_not_counted(): void
    {
        $this->exposed('ad', '2026-10-28 10:00:00', '2026-10-29 00:00:00', Carbon::parse('2026-10-28 11:00:00')->getTimestamp());
        $this->exposed('ad', '2026-10-20 10:00:00', '2026-10-21 00:00:00', null);

        $counts = $this->source->counts($this->exp(), Carbon::now(), null);

        $this->assertSame(2, $counts['extra']['exposed']['ad']);
        $this->assertSame(1, $counts['extra']['counted']['ad']);
        $this->assertSame(0, $counts['arms']['ad']['completes']);
    }

    public function test_not_back_counts_members_whose_last_access_is_not_an_hour_after_the_exposure(): void
    {
        $at = '2026-10-20 10:00:00';
        $this->exposed('ad', $at, $at);
        $this->exposed('ad', $at, '2026-10-20 10:30:00');
        $this->exposed('ad', $at, '2026-10-20 11:00:00');
        $this->exposed('ad', $at, '2026-10-25 09:00:00');
        $this->exposed('control', $at, '2026-10-22 00:00:00');

        $counts = $this->source->counts($this->exp(), Carbon::now(), null);

        $this->assertEquals(['bad' => 2, 'base' => 4], $counts['guards']['not_back']['ad'], 'the moment of the show and half an hour later are not back; exactly an hour is');
        $this->assertEquals(['bad' => 0, 'base' => 1], $counts['guards']['not_back']['control']);
    }

    public function test_only_its_own_type_and_experiment_count(): void
    {
        $this->exposed('ad', '2026-10-20 10:00:00', '2026-10-21 00:00:00', null, 'email');
        DB::table('ux_experiment_exposures')->insert([
            'experiment' => 'ha-other', 'arm' => 'ad', 'userid' => $this->createTestUser()->id,
            'subject_type' => 'hosting_ad', 'subject_id' => 1, 'exposed_at' => '2026-10-20 10:00:00',
        ]);

        $counts = $this->source->counts($this->exp(), Carbon::now(), null);

        $this->assertSame(0, $counts['extra']['exposed']['ad']);
        $this->assertSame(0, $counts['extra']['counted']['ad']);
    }
}
