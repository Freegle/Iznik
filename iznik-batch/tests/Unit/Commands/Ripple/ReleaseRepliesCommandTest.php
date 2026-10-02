<?php

namespace Tests\Unit\Commands\Ripple;

use App\Services\Ripple\RippleReplyService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReleaseRepliesCommandTest extends TestCase
{
    use \Tests\Support\SeedsReachCells;

    private const POLY = 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('DELETE FROM rippling_held_replies');
        DB::statement('DELETE FROM rippling_reach');
    }

    /** @return array{0:int,1:int} [ripplingRowId, chatmsgid] — a held reply INSIDE the reach. */
    private function seedHeldInsideReach(): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $this->reachCellsFor(self::POLY), self::POLY]
        );
        DB::table('rippling_reach')->where('msgid', $message->id)->update(['reach_labels' => 'label-bytes']);
        // The stored label admits the held replier's point, so the release
        // cron frees them - the label is the reach record, faked here.
        \Illuminate\Support\Facades\Http::fake(function ($request) {
            if (!str_contains($request->url(), 'reach-eval')) {
                return null;
            }
            $results = array_map(
                fn ($id) => ['msgid' => (int) $id, 'verdict' => 'in'],
                $request['msgids'] ?? []
            );

            return \Illuminate\Support\Facades\Http::response(['results' => $results]);
        });

        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $room = $this->createTestChatRoom($u1, $u2);
        $cm = $this->createTestChatMessage($room, $u1);
        $rowId = app(RippleReplyService::class)->hold($room->id, $cm->id, $message->id, $u1->id, 51.5, -0.1);

        return [$rowId, $cm->id, (int) $message->id];
    }

    /** A held reply for a post with NO reach row (msgid only). */
    private function seedHeldNoReach(): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $room = $this->createTestChatRoom($u1, $u2);
        $cm = $this->createTestChatMessage($room, $u1);
        $rowId = app(RippleReplyService::class)->hold($room->id, $cm->id, $message->id, $u1->id, 51.5, -0.1);

        return [$rowId, (int) $message->id];
    }

    public function test_releases_covered_replies(): void
    {
        [$rowId] = $this->seedHeldInsideReach();

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame('released', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_no_reach_but_post_still_active_does_not_mark_gone(): void
    {
        // Regression: a post transiently absent from messages_spatial (reach row gone)
        // but NOT actually taken/withdrawn must not have its replies wrongly marked gone.
        [$rowId] = $this->seedHeldNoReach(); // message is live, no outcome, no reach row

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame('held', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_no_reach_and_post_taken_marks_gone(): void
    {
        [$rowId, $msgid] = $this->seedHeldNoReach();
        DB::table('messages_outcomes')->insert(['msgid' => $msgid, 'outcome' => 'Taken', 'timestamp' => now()]);

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame('taken-gone', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_release_open_releases_stuck_reply_for_open_post(): void
    {
        // Backfill: a held reply for a post that is still open but whose reach never reached
        // the replier would otherwise stay 'held' indefinitely (see the no-reach regression
        // test above). --release-open releases it so it is delivered.
        [$rowId] = $this->seedHeldNoReach(); // open post, no reach row -> normally stays held

        $this->artisan('ripple:release-replies', ['--release-open' => true])->assertExitCode(0);

        $this->assertSame('released', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_release_open_still_marks_gone_for_taken_post(): void
    {
        // --release-open must not resurrect replies for genuinely gone posts.
        [$rowId, $msgid] = $this->seedHeldNoReach();
        DB::table('messages_outcomes')->insert(['msgid' => $msgid, 'outcome' => 'Taken', 'timestamp' => now()]);

        $this->artisan('ripple:release-replies', ['--release-open' => true])->assertExitCode(0);

        $this->assertSame('taken-gone', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_release_open_since_hours_only_releases_recent_replies(): void
    {
        // Scoped backfill: only held replies whose reply is within the window are released;
        // older held replies for the same still-open post are left alone (row-level).
        [$recentRow] = $this->seedHeldNoReach();
        [$oldRow, $oldMsgId] = $this->seedHeldNoReach();

        // Age the old reply's chat message beyond the window.
        $oldChatMsgId = DB::table('rippling_held_replies')->where('id', $oldRow)->value('chatmsgid');
        DB::table('chat_messages')->where('id', $oldChatMsgId)->update(['date' => now()->subHours(72)]);

        $this->artisan('ripple:release-replies', ['--release-open' => true, '--since-hours' => 48])
            ->assertExitCode(0);

        $this->assertSame('released', DB::table('rippling_held_replies')->where('id', $recentRow)->value('status'),
            'recent held reply for an open post is released');
        $this->assertSame('held', DB::table('rippling_held_replies')->where('id', $oldRow)->value('status'),
            'held reply older than the window is left alone');
    }

    public function test_release_open_since_hours_skips_gone_posts(): void
    {
        // A recent held reply whose post is gone is skipped by the scoped backfill (left for
        // the normal per-post release path to mark gone) — not released.
        [$rowId, $msgid] = $this->seedHeldNoReach();
        DB::table('messages_outcomes')->insert(['msgid' => $msgid, 'outcome' => 'Taken', 'timestamp' => now()]);

        $this->artisan('ripple:release-replies', ['--release-open' => true, '--since-hours' => 48])
            ->assertExitCode(0);

        $this->assertSame('held', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    /** A held reply OUTSIDE a still-expanding reach: the reach will never cover it. */
    private function seedHeldOutsideReach(): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $this->reachCellsFor(self::POLY), self::POLY]
        );

        $u1 = $this->createTestUser();
        $u2 = $this->createTestUser();
        $room = $this->createTestChatRoom($u1, $u2);
        $cm = $this->createTestChatMessage($room, $u1);
        $rowId = app(RippleReplyService::class)->hold($room->id, $cm->id, $message->id, $u1->id, 52.0, 1.0);

        return [$rowId, $cm->id, (int) $message->id];
    }

    public function test_a_reply_past_its_delay_is_delivered_even_though_the_reach_never_covers_it(): void
    {
        // Three in four held repliers are somewhere the ripple never gets to, so waiting
        // for coverage means waiting for the backstop days later. The delay has to be its
        // own exit from the hold.
        config(['freegle.ripple.reply_delay.enabled' => true]);
        [$rowId] = $this->seedHeldOutsideReach();
        DB::table('rippling_held_replies')->where('id', $rowId)
            ->update(['created_at' => now()->subDay(), 'dueat' => null]);

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame('released', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_a_reply_still_inside_its_delay_stays_held(): void
    {
        // Locals keep their head start: the delay is a delay, not an abolition of the hold.
        config(['freegle.ripple.reply_delay.enabled' => true]);
        [$rowId] = $this->seedHeldOutsideReach();

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame('held', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_a_gone_post_still_wins_over_a_due_delay(): void
    {
        // A due delay must never deliver a reply for an item that has already gone - the
        // replier is told it has gone instead (the Discourse 9808/#555 rule, via the
        // delay exit as well as the coverage one).
        config(['freegle.ripple.reply_delay.enabled' => true]);
        [$rowId, , $msgid] = $this->seedHeldOutsideReach();
        DB::table('rippling_held_replies')->where('id', $rowId)
            ->update(['created_at' => now()->subDay(), 'dueat' => null]);
        DB::table('messages_outcomes')->insert(['msgid' => $msgid, 'outcome' => 'Taken', 'timestamp' => now()]);

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame('taken-gone', DB::table('rippling_held_replies')->where('id', $rowId)->value('status'));
    }

    public function test_taken_post_with_lingering_done_reach_marks_gone_not_released(): void
    {
        // Discourse 9808/#555: a post can be marked Taken while its reach row lingers and only
        // later reaches status 'done'. The "gone" check must win over the 'done' branch — the
        // post is gone, so its held replies are told-gone (taken-gone), never released and
        // flooded into the offerer's inbox as if the item were still live.
        [$rowId, , $msgid] = $this->seedHeldInsideReach(); // reach row created ('expanding')
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['status' => 'done']);
        DB::table('messages_outcomes')->insert(['msgid' => $msgid, 'outcome' => 'Taken', 'timestamp' => now()]);

        $this->artisan('ripple:release-replies')->assertExitCode(0);

        $this->assertSame(
            'taken-gone',
            DB::table('rippling_held_replies')->where('id', $rowId)->value('status'),
            'a Taken post with a lingering done reach row is marked gone, not released'
        );
    }
}
