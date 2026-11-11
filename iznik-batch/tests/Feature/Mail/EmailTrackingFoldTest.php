<?php

namespace Tests\Feature\Mail;

use App\Services\Mail\EmailTrackingFoldService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * mail:tracking:fold applies the journal the Go delivery handlers append to (image loads and pixel
 * opens) to email_tracking and email_tracking_images. The contract it must keep is the one the
 * handlers used to keep inline: opened_at is the FIRST open, scroll_depth_percent the MAXIMUM, one
 * images row per load - and a re-run, or a crash half way, must not double anything.
 */
class EmailTrackingFoldTest extends TestCase
{
    private function tracking(array $extra = []): array
    {
        $tid = bin2hex(random_bytes(16));
        $id = (int) DB::table('email_tracking')->insertGetId(array_merge([
            'tracking_id' => $tid,
            'email_type' => 'UnifiedDigestDaily',
            'recipient_email' => 'r_' . uniqid('', true) . '@test.com',
            'sent_at' => Carbon::now()->subHours(5),
            'created_at' => Carbon::now()->subHours(5),
            'updated_at' => Carbon::now()->subHours(5),
        ], $extra));

        return [$id, $tid];
    }

    private function journal(string $ref, int $kind, ?string $position, ?int $scroll, $at): int
    {
        return (int) DB::table('email_tracking_journal')->insertGetId([
            'ref' => $ref,
            'kind' => $kind,
            'position' => $position,
            'scroll' => $scroll,
            'loaded_at' => $at,
        ]);
    }

    private function row(int $id): object
    {
        return DB::table('email_tracking')->where('id', $id)->first();
    }

    private function images(int $id)
    {
        return DB::table('email_tracking_images')->where('email_tracking_id', $id)->orderBy('id')->get();
    }

    private function fold(int $chunk = 5000): array
    {
        return app(EmailTrackingFoldService::class)->fold($chunk);
    }

    public function test_first_image_load_becomes_opened_at_and_every_load_becomes_a_row(): void
    {
        [$id, $tid] = $this->tracking();
        $t1 = Carbon::now()->subHours(3);
        $t2 = $t1->copy()->addSeconds(4);

        $this->journal($tid, 1, 'item_2', 40, $t2);
        $this->journal($tid, 1, 'item_1', 10, $t1);

        $this->fold();

        $row = $this->row($id);
        $this->assertSame($t1->toDateTimeString(), Carbon::parse($row->opened_at)->toDateTimeString(),
            'opened_at is the earliest load, whatever order the journal holds them in');
        $this->assertSame('image', $row->opened_via);
        $this->assertSame(40, (int) $row->scroll_depth_percent, 'deepest scroll estimate wins');

        $images = $this->images($id);
        $this->assertCount(2, $images);
        $this->assertEqualsCanonicalizing(['item_1', 'item_2'], $images->pluck('image_position')->all());
        $this->assertSame(0, DB::table('email_tracking_journal')->where('ref', $tid)->count(), 'journal rows are consumed');
    }

    public function test_compact_ref_resolves_to_the_tracking_row(): void
    {
        [$id, $tid] = $this->tracking();
        $this->journal(substr($tid, 0, 12), 1, 'i0', null, Carbon::now()->subHours(2));

        $this->fold();

        $this->assertNotNull($this->row($id)->opened_at);
        $this->assertCount(1, $this->images($id));
        $this->assertNull($this->row($id)->scroll_depth_percent, 'no estimate was sent, so none is invented');
    }

    public function test_pixel_open_stamps_opened_at_via_pixel_and_adds_no_image_row(): void
    {
        [$id, $tid] = $this->tracking();
        $this->journal($tid, 2, null, null, Carbon::now()->subHours(2));

        $this->fold();

        $this->assertSame('pixel', $this->row($id)->opened_via);
        $this->assertCount(0, $this->images($id));
    }

    public function test_existing_open_is_kept_unless_the_journal_is_earlier(): void
    {
        $earlier = Carbon::now()->subHours(4)->startOfSecond();
        $later = Carbon::now()->subHours(1)->startOfSecond();

        // Opened by a click at $earlier: a later image load must not move it.
        [$a, $ta] = $this->tracking(['opened_at' => $earlier, 'opened_via' => 'click']);
        $this->journal($ta, 1, 'item_1', null, $later);

        // Opened by a click at $later, but an image really loaded at $earlier and is only now folded:
        // the true first open wins.
        [$b, $tb] = $this->tracking(['opened_at' => $later, 'opened_via' => 'click']);
        $this->journal($tb, 1, 'item_1', null, $earlier);

        $this->fold();

        $ra = $this->row($a);
        $this->assertSame($earlier->toDateTimeString(), Carbon::parse($ra->opened_at)->toDateTimeString());
        $this->assertSame('click', $ra->opened_via);

        $rb = $this->row($b);
        $this->assertSame($earlier->toDateTimeString(), Carbon::parse($rb->opened_at)->toDateTimeString());
        $this->assertSame('image', $rb->opened_via);
    }

    public function test_scroll_depth_never_decreases(): void
    {
        [$id, $tid] = $this->tracking(['scroll_depth_percent' => 70]);
        $this->journal($tid, 1, 'item_1', 30, Carbon::now()->subHours(2));

        $this->fold();

        $this->assertSame(70, (int) $this->row($id)->scroll_depth_percent);
        $this->assertCount(1, $this->images($id), 'the load is still recorded');
    }

    public function test_rerun_is_a_no_op_and_cannot_double_images(): void
    {
        [$id, $tid] = $this->tracking();
        $this->journal($tid, 1, 'item_1', 55, Carbon::now()->subHours(2));

        $this->fold();
        $before = $this->row($id);
        $second = $this->fold();

        $this->assertSame(0, $second['events']);
        $this->assertCount(1, $this->images($id));
        $this->assertEquals($before->opened_at, $this->row($id)->opened_at);
    }

    public function test_unknown_refs_are_dropped_and_counted(): void
    {
        $this->journal('nosuchtrackingid1', 1, 'item_1', 1, Carbon::now()->subHours(2));

        $stats = $this->fold();

        $this->assertSame(1, $stats['unresolved']);
        $this->assertSame(0, DB::table('email_tracking_journal')->where('ref', 'nosuchtrackingid1')->count());
    }

    public function test_chunking_gives_the_same_answer_as_one_pass(): void
    {
        [$id, $tid] = $this->tracking();
        $base = Carbon::now()->subHours(3);
        for ($i = 0; $i < 25; $i++) {
            $this->journal($tid, 1, 'item_' . $i, $i * 3, $base->copy()->addSeconds($i));
        }

        $stats = $this->fold(7);

        $this->assertSame(25, $stats['events']);
        $this->assertGreaterThanOrEqual(4, $stats['chunks']);
        $this->assertCount(25, $this->images($id));
        $this->assertSame(72, (int) $this->row($id)->scroll_depth_percent);
        $this->assertSame($base->toDateTimeString(), Carbon::parse($this->row($id)->opened_at)->toDateTimeString());
    }

    public function test_events_arriving_after_the_fold_starts_are_left_for_the_next_run(): void
    {
        [$id, $tid] = $this->tracking();
        $this->journal($tid, 1, 'item_1', 5, Carbon::now()->subHours(2));

        // Pin the ceiling below a row that lands "during" the run.
        $ceiling = (int) DB::table('email_tracking_journal')->max('id');
        $late = $this->journal($tid, 1, 'item_late', 99, Carbon::now()->subHours(1));

        $stats = app(EmailTrackingFoldService::class)->fold(5000, $ceiling);

        $this->assertSame(1, $stats['events']);
        $this->assertSame(1, DB::table('email_tracking_journal')->where('id', $late)->count());
        $this->assertSame(5, (int) $this->row($id)->scroll_depth_percent);
    }

    public function test_fold_command_reports_and_marks_digest_posts_seen_for_folded_opens(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg = $this->createTestMessage($this->createTestUser(), $group);

        [$id, $tid] = $this->tracking([
            'userid' => $user->id,
            'metadata' => json_encode(['post_count' => 1, 'post_msgids' => [$msg->id]]),
        ]);
        // Opened 20 hours ago: far outside mark-seen's hourly 3 hour window, which is why the fold
        // has to drive it for opens it applies.
        $this->journal($tid, 1, 'item_1', 20, Carbon::now()->subHours(20));

        $this->artisan('mail:tracking:fold')->assertExitCode(0);

        $this->assertSame(1, DB::table('messages_likes')
            ->where('msgid', $msg->id)->where('userid', $user->id)->where('type', 'View')->count());
    }

    public function test_fold_is_scheduled_in_the_overnight_trough_away_from_the_03_00_cluster(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'mail:tracking:fold'));

        $this->assertNotNull($event, 'mail:tracking:fold is scheduled');
        // Daily, between 21:00 and 05:00 UTC, and not in the 03:00-03:05 spatial rebuild minutes.
        $parts = explode(' ', $event->expression);
        $minute = (int) $parts[0];
        $hour = (int) $parts[1];
        $this->assertTrue($hour >= 21 || $hour < 5, "hour {$hour} is in the 21:00-05:00 trough");
        $this->assertFalse($hour === 3 && $minute < 10, 'clear of the 03:00 cluster');
        $this->assertSame('*', $parts[2]);
    }
}
