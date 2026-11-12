<?php

namespace Tests\Feature\Chat;

use App\Mail\Chat\ChatNotification;
use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature tests for chats:chaseup-expected (migration of the legacy V1 PHP
 * chat_chaseup_expected cron script → ChatRoom::chaseupExpected()).
 */
class ChaseupExpectedCommandTest extends TestCase
{
    /**
     * Build an eligible "waiting for reply" scenario:
     * - User2User chat with a roster for both users (status Online).
     * - A message from the expecter, flagged replyexpected=1 / replyreceived=0,
     *   dated within the last 5 days.
     * - The expectee has accessed the site >= 24h after the message.
     *
     * @return array{0: User, 1: User, 2: ChatRoom, 3: int} [expecter, expectee, room, msgId]
     */
    private function makeExpectedScenario(array $overrides = []): array
    {
        $expecter = $this->createTestUser();
        // Expectee last accessed 1 day ago — well after the (3-day-old) message.
        $expectee = $this->createTestUser(['lastaccess' => now()->subDay()]);

        $room = $this->createTestChatRoom($expecter, $expectee, [
            'chattype' => ChatRoom::TYPE_USER2USER,
        ]);

        $message = $this->createTestChatMessage($room, $expecter, array_merge([
            'date' => now()->subDays(3),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ], $overrides));

        foreach ([$expecter->id, $expectee->id] as $uid) {
            DB::table('chat_roster')->insert([
                'chatid' => $room->id,
                'userid' => $uid,
                'date' => now(),
                'status' => 'Online',
            ]);
        }

        DB::table('users_expected')->insert([
            'expecter' => $expecter->id,
            'expectee' => $expectee->id,
            'chatmsgid' => $message->id,
            'value' => -1,
        ]);

        return [$expecter, $expectee, $room, $message->id];
    }

    public function test_smoke_no_expected_messages(): void
    {
        Mail::fake();

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 0 message(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_chases_up_eligible_expected_reply(): void
    {
        Mail::fake();

        [, $expectee] = $this->makeExpectedScenario();

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 1 message(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
        Mail::assertSent(ChatNotification::class, function (ChatNotification $mail) use ($expectee) {
            return $mail->hasTo($expectee->email_preferred)
                && $mail->waitingForReply === true
                && str_starts_with($mail->replySubject, 'WAITING FOR REPLY:');
        });
    }

    public function test_skips_when_reply_already_received(): void
    {
        Mail::fake();

        $this->makeExpectedScenario(['replyreceived' => 1]);

        $this->artisan('chats:chaseup-expected')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_when_reply_not_expected(): void
    {
        Mail::fake();

        $this->makeExpectedScenario(['replyexpected' => 0]);

        $this->artisan('chats:chaseup-expected')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_message_older_than_window(): void
    {
        Mail::fake();

        // 10 days old — before the 5-day eligibility window.
        $this->makeExpectedScenario(['date' => now()->subDays(10)]);

        $this->artisan('chats:chaseup-expected')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_when_expectee_not_active_after_grace(): void
    {
        Mail::fake();

        // Message 3 days ago; build scenario then move expectee lastaccess to
        // just after the message (< 24h gap) so TIMESTAMPDIFF < 1440 minutes.
        [, $expectee, , ] = $this->makeExpectedScenario();
        DB::table('users')->where('id', $expectee->id)
            ->update(['lastaccess' => now()->subDays(3)->addMinutes(60)]);

        $this->artisan('chats:chaseup-expected')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_blocked_roster(): void
    {
        Mail::fake();

        [, $expectee, $room] = $this->makeExpectedScenario();
        DB::table('chat_roster')
            ->where('chatid', $room->id)
            ->where('userid', $expectee->id)
            ->update(['status' => 'Blocked']);

        $this->artisan('chats:chaseup-expected')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_non_user2user_chat(): void
    {
        Mail::fake();

        [, , $room] = $this->makeExpectedScenario();
        DB::table('chat_rooms')->where('id', $room->id)
            ->update(['chattype' => ChatRoom::TYPE_USER2MOD]);

        $this->artisan('chats:chaseup-expected')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_when_email_notifications_off(): void
    {
        Mail::fake();

        [, $expectee] = $this->makeExpectedScenario();
        DB::table('users')->where('id', $expectee->id)
            ->update(['settings' => json_encode(['notifications' => ['email' => false]])]);

        $this->artisan('chats:chaseup-expected')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_one_email_per_expectee_per_chat(): void
    {
        Mail::fake();

        // Two qualifying messages in the same chat for the same expectee should
        // still chase the user only once (V1 GROUP BY expectee, chatid).
        [$expecter, $expectee, $room] = $this->makeExpectedScenario();

        // NOT subDays(2): the expectee's lastaccess is now()-1d, so a message
        // at now()-2d sits on the exact TIMESTAMPDIFF >= 1440-minute grace
        // boundary - whether it qualified depended on sub-second timing
        // between the two now() calls, so the dedupe path only sometimes ran
        // (flipping its coverage on unrelated PRs) and this test only
        // sometimes tested what its name claims. 2h of headroom makes the
        // second row qualify every run.
        $message2 = $this->createTestChatMessage($room, $expecter, [
            'date' => now()->subDays(2)->subHours(2),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);
        DB::table('users_expected')->insert([
            'expecter' => $expecter->id,
            'expectee' => $expectee->id,
            'chatmsgid' => $message2->id,
            'value' => -1,
        ]);

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 1 message(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_message_rejected_in_chat_review(): void
    {
        Mail::fake();

        $this->makeExpectedScenario(['reviewrejected' => 1]);

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 0 message(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_message_still_held_for_review(): void
    {
        Mail::fake();

        $this->makeExpectedScenario(['reviewrequired' => 1]);

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 0 message(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_deleted_message(): void
    {
        Mail::fake();

        $this->makeExpectedScenario(['deleted' => 1]);

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 0 message(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    /**
     * The command is daily and a message stays eligible for five days, so a message that is
     * still unanswered would be chased on every run unless a run records that it chased it.
     */
    public function test_an_unanswered_message_is_chased_once_not_on_every_daily_run(): void
    {
        Mail::fake();

        $this->makeExpectedScenario();

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 1 message(s)')
            ->assertExitCode(0);

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 0 message(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_message_still_awaiting_processing(): void
    {
        Mail::fake();

        $this->makeExpectedScenario(['processingrequired' => 1]);

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 0 message(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_message_whose_processing_failed(): void
    {
        Mail::fake();

        $this->makeExpectedScenario(['processingsuccessful' => 0]);

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 0 message(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_reply_held_by_rippling_until_it_is_released(): void
    {
        Mail::fake();

        [$expecter, , $room, $msgId] = $this->makeExpectedScenario();

        $group = $this->createTestGroup();
        $post = $this->createTestMessage($expecter, $group);
        $rowId = (int) DB::table('rippling_held_replies')->insertGetId([
            'chatid' => $room->id,
            'chatmsgid' => $msgId,
            'msgid' => $post->id,
            'replieruserid' => $expecter->id,
            'lat' => 51.5,
            'lng' => -0.1,
            'status' => 'held',
            'created_at' => now(),
        ]);

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 0 message(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();

        DB::table('rippling_held_replies')->where('id', $rowId)->update(['status' => 'released']);

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 1 message(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_dry_run_sends_nothing(): void
    {
        Mail::fake();

        $this->makeExpectedScenario();

        $this->artisan('chats:chaseup-expected', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would chase up 1 message(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('chat_chaseup_expected_sent')->count(), 'a dry run records nothing');
    }

    /**
     * Every deploy clears the cache, so what was chased has to be remembered in the database.
     */
    public function test_a_second_run_after_a_cache_flush_sends_nothing(): void
    {
        Mail::fake();

        [, $expectee, , $msgId] = $this->makeExpectedScenario();

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 1 message(s)')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('chat_chaseup_expected_sent')
            ->where('chatmsgid', $msgId)->where('userid', $expectee->id)->count());

        Cache::flush();

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 0 message(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    /**
     * The newest unanswered message in a chat is the one chased. Once it has been chased, an older
     * unanswered message in the same chat must not be chased in its place.
     */
    public function test_an_older_message_is_not_chased_after_the_newest_was(): void
    {
        Mail::fake();

        [$expecter, $expectee, $room] = $this->makeExpectedScenario();
        $newer = $this->createTestChatMessage($room, $expecter, [
            // Newer than the first message but still a day before the expectee's last visit.
            'date' => now()->subDays(3)->addHours(2),
            'replyexpected' => 1,
            'replyreceived' => 0,
        ]);
        DB::table('users_expected')->insert([
            'expecter' => $expecter->id,
            'expectee' => $expectee->id,
            'chatmsgid' => $newer->id,
            'value' => -1,
        ]);

        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 1 message(s)')
            ->assertExitCode(0);
        Cache::flush();
        $this->artisan('chats:chaseup-expected')
            ->expectsOutputToContain('Chased up 0 message(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_closed_roster(): void
    {
        Mail::fake();

        [, $expectee, $room] = $this->makeExpectedScenario();
        DB::table('chat_roster')
            ->where('chatid', $room->id)
            ->where('userid', $expectee->id)
            ->update(['status' => 'Closed']);

        $this->artisan('chats:chaseup-expected')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_deleted_account(): void
    {
        Mail::fake();

        [, $expectee] = $this->makeExpectedScenario();
        DB::table('users')->where('id', $expectee->id)->update(['deleted' => now()]);

        $this->artisan('chats:chaseup-expected')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_limit_caps_one_run_and_the_oldest_go_first(): void
    {
        Mail::fake();

        [, , , $oldMsg] = $this->makeExpectedScenario(['date' => now()->subDays(4)]);
        [, , , $newMsg] = $this->makeExpectedScenario(['date' => now()->subDays(3)]);

        $this->artisan('chats:chaseup-expected', ['--limit' => 1])
            ->expectsOutputToContain('Chased up 1 message(s)')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('chat_chaseup_expected_sent')->where('chatmsgid', $oldMsg)->count());
        $this->assertSame(0, DB::table('chat_chaseup_expected_sent')->where('chatmsgid', $newMsg)->count());

        // The backlog drains on the next run.
        $this->artisan('chats:chaseup-expected', ['--limit' => 1])
            ->expectsOutputToContain('Chased up 1 message(s)')
            ->assertExitCode(0);
        $this->assertSame(1, DB::table('chat_chaseup_expected_sent')->where('chatmsgid', $newMsg)->count());
        Mail::assertSentCount(2);
    }

    public function test_dry_run_with_a_limit_reports_how_many_it_would_send(): void
    {
        Mail::fake();

        $this->makeExpectedScenario();
        $this->makeExpectedScenario();
        $this->makeExpectedScenario();

        $this->artisan('chats:chaseup-expected', ['--dry-run' => true, '--limit' => 2])
            ->expectsOutputToContain('Would chase up 2 message(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_old_sent_records_are_pruned(): void
    {
        Mail::fake();

        DB::table('chat_chaseup_expected_sent')->insert([
            ['chatmsgid' => 1, 'userid' => 1, 'sent' => now()->subDays(30)],
            ['chatmsgid' => 2, 'userid' => 1, 'sent' => now()->subDay()],
        ]);

        $this->artisan('chats:chaseup-expected')->assertExitCode(0);

        $this->assertSame(0, DB::table('chat_chaseup_expected_sent')->where('chatmsgid', 1)->count());
        $this->assertSame(1, DB::table('chat_chaseup_expected_sent')->where('chatmsgid', 2)->count());
    }

    public function test_the_scheduled_run_passes_a_limit_of_500(): void
    {
        config([
            'freegle.schedule.profile' => 'full',
            'freegle.schedule.overlay' => null,
        ]);

        $schedule = new \Illuminate\Console\Scheduling\Schedule();
        $this->app->instance(\Illuminate\Console\Scheduling\Schedule::class, $schedule);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        require base_path('routes/console.php');

        $commands = [];
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'chats:chaseup-expected')) {
                $commands[] = (string) $event->command;
            }
        }

        $this->assertCount(1, $commands);
        $this->assertStringContainsString('--limit=500', $commands[0]);
    }
}
