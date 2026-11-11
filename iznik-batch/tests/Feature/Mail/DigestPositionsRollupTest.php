<?php

namespace Tests\Feature\Mail;

use App\Services\Mail\DigestPositionsRollupService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * email:digest-positions-rollup must produce the same figures as the Go handler DigestClickPositions
 * computes live (iznik-server-go/emailtracking). The fixture here is hand-counted.
 */
class DigestPositionsRollupTest extends TestCase
{
    private const DAY = '2026-09-10';

    private int $plainUser;

    private int $tnUser;

    protected function setUp(): void
    {
        parent::setUp();

        // DatabaseTransactions rolls these back.
        DB::table('email_tracking')->where('sent_at', '>=', '2026-09-09 00:00:00')
            ->where('sent_at', '<=', '2026-09-12 23:59:59')->delete();
        DB::table('email_digest_positions_daily')->delete();

        $this->plainUser = $this->createTestUser()->id;
        $this->tnUser = $this->createTestUser(['tnuserid' => random_int(1000000, 9000000)])->id;
    }

    private function digest(string $type, string $sentAt, ?int $posts, ?int $userid = null): int
    {
        return DB::table('email_tracking')->insertGetId([
            'tracking_id' => bin2hex(random_bytes(8)),
            'email_type' => $type,
            'userid' => $userid ?? $this->plainUser,
            'recipient_email' => 'r'.uniqid('', true).'@test.com',
            'sent_at' => $sentAt,
            'metadata' => $posts === null ? null : json_encode(['post_msgids' => $posts > 0 ? range(1, $posts) : []]),
            'created_at' => $sentAt,
            'updated_at' => $sentAt,
        ]);
    }

    private function click(int $trackingId, string $position): void
    {
        DB::table('email_tracking_clicks')->insert([
            'email_tracking_id' => $trackingId,
            'link_url' => 'https://www.ilovefreegle.org/message/1',
            'link_position' => $position,
            'clicked_at' => self::DAY.' 12:00:00',
        ]);
    }

    /** @return array<int, object> keyed by position */
    private function rows(string $type, string $day = self::DAY): array
    {
        return DB::table('email_digest_positions_daily')
            ->where('day', $day)->where('email_type', $type)->orderBy('position')
            ->get()->keyBy('position')->all();
    }

    private function fixture(): void
    {
        // Three digests of UnifiedDigestDaily: sizes 3, 3 and 1.
        $a = $this->digest('UnifiedDigestDaily', self::DAY.' 07:00:00', 3);
        $b = $this->digest('UnifiedDigestDaily', self::DAY.' 23:59:59', 3);
        $c = $this->digest('UnifiedDigestDaily', self::DAY.' 00:00:00', 1);

        // A clicks position 0 twice by the legacy label and position 1 by the compact one.
        $this->click($a, 'post_0');
        $this->click($a, 'post_0');
        $this->click($a, 'p1');
        // B clicks position 0 by the compact label.
        $this->click($b, 'p0');
        // Summary and image links say nothing about the position, and other labels are not positions.
        $this->click($c, 'y0');
        $this->click($c, 'i0');
        $this->click($c, 'summary_0');

        // Excluded from the cohort: Trash Nothing recipient, no metadata, no posts, other day, other type.
        $tn = $this->digest('UnifiedDigestDaily', self::DAY.' 08:00:00', 3, $this->tnUser);
        $this->click($tn, 'p0');
        $this->digest('UnifiedDigestDaily', self::DAY.' 08:00:00', null);
        $this->digest('UnifiedDigestDaily', self::DAY.' 08:00:00', 0);
        $other = $this->digest('UnifiedDigestDaily', '2026-09-11 00:00:00', 3);
        $this->click($other, 'p0');
        $this->digest('ChatNotification', self::DAY.' 08:00:00', 3);

        // A second digest type on the same day.
        $d = $this->digest('UnifiedDigestImmediate', self::DAY.' 09:00:00', 2);
        $this->click($d, 'post_1');
    }

    public function test_counts_per_position_match_hand_computed_fixture(): void
    {
        $this->fixture();

        $written = (new DigestPositionsRollupService)->rollupDay(Carbon::parse(self::DAY, 'UTC'));

        $daily = $this->rows('UnifiedDigestDaily');
        $this->assertCount(3, $daily);
        // Sizes 3, 3, 1: position 0 shown by all three, positions 1 and 2 by two.
        $this->assertSame([3, 2, 2], [(int) $daily[0]->shown, (int) $daily[1]->shown, (int) $daily[2]->shown]);
        // Position 0: A (two clicks, legacy label) and B (compact); emails_clicked 2, clicks 3.
        $this->assertSame([2, 3], [(int) $daily[0]->emails_clicked, (int) $daily[0]->clicks]);
        $this->assertSame([1, 1], [(int) $daily[1]->emails_clicked, (int) $daily[1]->clicks]);
        $this->assertSame([0, 0], [(int) $daily[2]->emails_clicked, (int) $daily[2]->clicks]);

        $immediate = $this->rows('UnifiedDigestImmediate');
        $this->assertSame([1, 1], [(int) $immediate[0]->shown, (int) $immediate[1]->shown]);
        $this->assertSame([0, 1], [(int) $immediate[0]->emails_clicked, (int) $immediate[1]->emails_clicked]);

        $this->assertSame(5, $written);
        $this->assertCount(0, $this->rows('ChatNotification'));
    }

    public function test_writes_a_marker_for_a_day_with_no_digests(): void
    {
        (new DigestPositionsRollupService)->rollupDay(Carbon::parse('2026-09-12', 'UTC'));

        $this->assertSame(1, DB::table('email_digest_positions_daily')->where('day', '2026-09-12')->count());
        $this->assertCount(1, $this->rows('', '2026-09-12'));
    }

    public function test_rerun_is_idempotent(): void
    {
        $this->fixture();
        $service = new DigestPositionsRollupService;

        $service->rollupDay(Carbon::parse(self::DAY, 'UTC'));
        $first = DB::table('email_digest_positions_daily')->where('day', self::DAY)->count();
        $service->rollupDay(Carbon::parse(self::DAY, 'UTC'));

        $this->assertSame($first, DB::table('email_digest_positions_daily')->where('day', self::DAY)->count());
        $this->assertSame(3, (int) $this->rows('UnifiedDigestDaily')[0]->clicks);
    }

    public function test_recent_days_recompute_picks_up_a_late_click(): void
    {
        Carbon::setTestNow('2026-09-12 05:35:00');
        $this->fixture();

        $this->artisan('email:digest-positions-rollup', ['--days' => 3])->assertSuccessful();
        $this->assertSame(3, (int) $this->rows('UnifiedDigestDaily')[0]->clicks);

        // A click arrives two days after the digest went out.
        $id = DB::table('email_tracking')->where('email_type', 'UnifiedDigestDaily')
            ->where('sent_at', self::DAY.' 00:00:00')->value('id');
        $this->click($id, 'p0');

        $this->artisan('email:digest-positions-rollup', ['--days' => 3])->assertSuccessful();

        $row = $this->rows('UnifiedDigestDaily')[0];
        $this->assertSame([3, 4], [(int) $row->emails_clicked, (int) $row->clicks]);
        Carbon::setTestNow();
    }

    public function test_never_rolls_today_or_later(): void
    {
        Carbon::setTestNow('2026-09-12 05:35:00');

        $this->artisan('email:digest-positions-rollup', ['--from' => '2026-09-10', '--to' => '2026-09-20'])
            ->assertSuccessful();

        $days = DB::table('email_digest_positions_daily')->orderBy('day')->pluck('day')->unique()->values()->all();
        $this->assertSame(['2026-09-10', '2026-09-11'], $days);
        Carbon::setTestNow();
    }

    public function test_rejects_a_bad_date(): void
    {
        $this->artisan('email:digest-positions-rollup', ['--from' => '2026-02-31'])->assertFailed();
    }

    public function test_default_window_comes_from_config(): void
    {
        Carbon::setTestNow('2026-09-12 05:35:00');
        config(['freegle.digest_positions_rollup.days' => 2]);

        $this->artisan('email:digest-positions-rollup')->assertSuccessful();

        $days = DB::table('email_digest_positions_daily')->pluck('day')->unique()->sort()->values()->all();
        $this->assertSame(['2026-09-10', '2026-09-11'], $days);
        Carbon::setTestNow();
    }

    public function test_is_scheduled_outside_the_backup_drain_window(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'email:digest-positions-rollup'));

        $this->assertNotNull($event);
        $this->assertSame('35 5 * * *', $event->expression);
    }
}
