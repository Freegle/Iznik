<?php

namespace Tests\Feature\Chat;

use App\Mail\Chat\ChatNotification;
use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature tests for chats:chaseup-expected (migration of
 * iznik-server cron/chat_chaseup_expected.php → ChatRoom::chaseupExpected()).
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

        $message2 = $this->createTestChatMessage($room, $expecter, [
            'date' => now()->subDays(2),
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

    public function test_dry_run_sends_nothing(): void
    {
        Mail::fake();

        $this->makeExpectedScenario();

        $this->artisan('chats:chaseup-expected', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would chase up 1 message(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
