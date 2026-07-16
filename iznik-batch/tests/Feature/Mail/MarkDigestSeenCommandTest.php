<?php

namespace Tests\Feature\Mail;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * mail:digest:mark-seen turns "opened/clicked a digest" into a per-(member, post)
 * 'View' marker - the same signal the browse feed and the daily digest sink
 * already-seen posts on. Sending alone must NOT mark seen.
 */
class MarkDigestSeenCommandTest extends TestCase
{
    private function seedDigest(int $userid, array $msgids, array $times): int
    {
        return (int) DB::table('email_tracking')->insertGetId(array_merge([
            'tracking_id' => bin2hex(random_bytes(8)),
            'email_type' => 'UnifiedDigestDaily',
            'userid' => $userid,
            'recipient_email' => 'r_' . uniqid('', true) . '@test.com',
            'metadata' => json_encode(['post_count' => count($msgids), 'post_msgids' => $msgids]),
            'sent_at' => Carbon::now()->subHours(1),
            'created_at' => Carbon::now()->subHours(1),
            'updated_at' => Carbon::now(),
        ], $times));
    }

    private function seenCount(int $msgid, int $userid): int
    {
        return DB::table('messages_likes')
            ->where('msgid', $msgid)->where('userid', $userid)->where('type', 'View')->count();
    }

    public function test_opened_digest_marks_its_posts_seen_for_the_recipient(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $a = $this->createTestMessage($poster, $group);
        $b = $this->createTestMessage($poster, $group);

        $this->seedDigest($user->id, [$a->id, $b->id], ['opened_at' => Carbon::now()->subMinutes(10)]);

        $this->artisan('mail:digest:mark-seen')->assertExitCode(0);

        $this->assertSame(1, $this->seenCount($a->id, $user->id), 'post A marked seen');
        $this->assertSame(1, $this->seenCount($b->id, $user->id), 'post B marked seen');
    }

    public function test_clicked_digest_marks_seen(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg = $this->createTestMessage($this->createTestUser(), $group);

        // Clicks are detected via email_tracking_clicks (indexed clicked_at), not the
        // denormalised email_tracking.clicked_at.
        $etid = $this->seedDigest($user->id, [$msg->id], ['clicked_at' => Carbon::now()->subMinutes(5)]);
        DB::table('email_tracking_clicks')->insert([
            'email_tracking_id' => $etid,
            'link_url' => 'https://www.ilovefreegle.org/message/' . $msg->id,
            'clicked_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->artisan('mail:digest:mark-seen')->assertExitCode(0);
        $this->assertSame(1, $this->seenCount($msg->id, $user->id));
    }

    public function test_sent_but_not_opened_digest_does_not_mark_seen(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg = $this->createTestMessage($this->createTestUser(), $group);

        // Delivered, never opened or clicked - we must not sink a post the member
        // may never have had a chance to see.
        $this->seedDigest($user->id, [$msg->id], ['delivered_at' => Carbon::now()->subMinutes(10)]);

        $this->artisan('mail:digest:mark-seen')->assertExitCode(0);
        $this->assertSame(0, $this->seenCount($msg->id, $user->id));
    }

    public function test_open_outside_the_lookback_window_is_ignored(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg = $this->createTestMessage($this->createTestUser(), $group);

        $this->seedDigest($user->id, [$msg->id], ['opened_at' => Carbon::now()->subHours(10)]);

        $this->artisan('mail:digest:mark-seen --hours=3')->assertExitCode(0);
        $this->assertSame(0, $this->seenCount($msg->id, $user->id));
    }

    public function test_reprocessing_is_idempotent(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg = $this->createTestMessage($this->createTestUser(), $group);

        $this->seedDigest($user->id, [$msg->id], ['opened_at' => Carbon::now()->subMinutes(10)]);

        $this->artisan('mail:digest:mark-seen')->assertExitCode(0);
        $this->artisan('mail:digest:mark-seen')->assertExitCode(0);

        $this->assertSame(1, $this->seenCount($msg->id, $user->id), 'no duplicate seen rows');
    }
}
