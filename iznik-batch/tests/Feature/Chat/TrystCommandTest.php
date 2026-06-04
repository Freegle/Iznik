<?php

namespace Tests\Feature\Chat;

use App\Mail\Tryst\TrystCalendarInviteMail;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\TrystService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TrystCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function createTryst(User $user1, User $user2, array $attributes = []): int
    {
        $arrangedfor = $attributes['arrangedfor']
            ?? now()->addDays(2)->setTime(14, 0)->format('Y-m-d H:i:s');

        return DB::table('trysts')->insertGetId(array_merge([
            'user1' => $user1->id,
            'user2' => $user2->id,
            'arrangedat' => now(),
            'arrangedfor' => $arrangedfor,
            'icssent' => 0,
            'remindersent' => null,
        ], $attributes));
    }

    public function test_command_runs_cleanly_with_no_trysts(): void
    {
        $this->artisan('chats:send-tryst-reminders')
            ->assertExitCode(0);
    }

    public function test_dry_run_flag_is_accepted(): void
    {
        $this->artisan('chats:send-tryst-reminders', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_sends_calendar_invite_for_future_tryst(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $this->createTryst($user1, $user2, [
            'arrangedfor' => now()->addDays(2)->setTime(14, 0)->format('Y-m-d H:i:s'),
            'icssent' => 0,
        ]);

        (new TrystService())->sendCalendarsDue(false);

        Mail::assertSent(TrystCalendarInviteMail::class, 2);
    }

    public function test_does_not_send_calendar_for_same_day_tryst(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // arrangedfor = today — V1 skips same-day to avoid annoying users
        $this->createTryst($user1, $user2, [
            'arrangedfor' => now()->setTime(18, 0)->format('Y-m-d H:i:s'),
            'icssent' => 0,
        ]);

        (new TrystService())->sendCalendarsDue(false);

        Mail::assertNothingSent();
    }

    public function test_does_not_resend_calendar_when_already_sent(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $this->createTryst($user1, $user2, [
            'arrangedfor' => now()->addDays(2)->setTime(14, 0)->format('Y-m-d H:i:s'),
            'icssent' => 1,
        ]);

        (new TrystService())->sendCalendarsDue(false);

        Mail::assertNothingSent();
    }

    public function test_skips_tryst_with_no_specific_time(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // All-day tryst (midnight time — no specific time agreed)
        $this->createTryst($user1, $user2, [
            'arrangedfor' => now()->addDays(2)->format('Y-m-d') . ' 00:00:00',
            'icssent' => 0,
        ]);

        (new TrystService())->sendCalendarsDue(false);

        Mail::assertNothingSent();
    }

    public function test_marks_icssent_after_sending_calendar(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $trystId = $this->createTryst($user1, $user2, [
            'arrangedfor' => now()->addDays(2)->setTime(14, 0)->format('Y-m-d H:i:s'),
            'icssent' => 0,
        ]);

        (new TrystService())->sendCalendarsDue(false);

        $this->assertSame(1, (int) DB::table('trysts')->where('id', $trystId)->value('icssent'));
    }

    public function test_dry_run_does_not_send_calendar_emails(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $this->createTryst($user1, $user2, [
            'arrangedfor' => now()->addDays(2)->setTime(14, 0)->format('Y-m-d H:i:s'),
            'icssent' => 0,
        ]);

        (new TrystService())->sendCalendarsDue(true);

        Mail::assertNothingSent();
    }

    public function test_sends_reminder_for_tryst_due_in_1_hour(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // arranged yesterday, due in 1 hour — should get a reminder
        $this->createTryst($user1, $user2, [
            'arrangedat' => now()->subDay(),
            'arrangedfor' => now()->addHour()->format('Y-m-d H:i:s'),
            'remindersent' => null,
        ]);

        $sent = (new TrystService())->sendRemindersDue(false);

        $this->assertSame(1, $sent);

        // A Reminder chat message should exist
        $message = ChatMessage::where('userid', $user1->id)
            ->where('type', 'Reminder')
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('Automatic reminder', $message->message);
    }

    public function test_does_not_send_reminder_for_same_day_arrangement(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // arranged today — V1 only reminds for arrangements made before today
        $this->createTryst($user1, $user2, [
            'arrangedat' => now(),
            'arrangedfor' => now()->addHour()->format('Y-m-d H:i:s'),
            'remindersent' => null,
        ]);

        $sent = (new TrystService())->sendRemindersDue(false);

        $this->assertSame(0, $sent);
    }

    public function test_does_not_send_reminder_when_already_sent(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $this->createTryst($user1, $user2, [
            'arrangedat' => now()->subDay(),
            'arrangedfor' => now()->addHour()->format('Y-m-d H:i:s'),
            'remindersent' => now()->subHour(),
        ]);

        $sent = (new TrystService())->sendRemindersDue(false);

        $this->assertSame(0, $sent);
    }

    public function test_reminder_reuses_existing_chat_room(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // Pre-create a chat room
        $room = $this->createTestChatRoom($user1, $user2, ['chattype' => ChatRoom::TYPE_USER2USER]);

        $this->createTryst($user1, $user2, [
            'arrangedat' => now()->subDay(),
            'arrangedfor' => now()->addHour()->format('Y-m-d H:i:s'),
            'remindersent' => null,
        ]);

        (new TrystService())->sendRemindersDue(false);

        // Only one chat room should exist
        $count = DB::table('chat_rooms')
            ->where(function ($q) use ($user1, $user2) {
                $q->where('user1', $user1->id)->where('user2', $user2->id);
            })
            ->orWhere(function ($q) use ($user1, $user2) {
                $q->where('user1', $user2->id)->where('user2', $user1->id);
            })
            ->where('chattype', 'User2User')
            ->count();

        $this->assertSame(1, $count);

        // Message should be in the existing room
        $this->assertSame(
            1,
            ChatMessage::where('chatid', $room->id)->where('type', 'Reminder')->count()
        );
    }

    public function test_marks_remindersent_after_sending(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $trystId = $this->createTryst($user1, $user2, [
            'arrangedat' => now()->subDay(),
            'arrangedfor' => now()->addHour()->format('Y-m-d H:i:s'),
            'remindersent' => null,
        ]);

        (new TrystService())->sendRemindersDue(false);

        $this->assertNotNull(DB::table('trysts')->where('id', $trystId)->value('remindersent'));
    }

    /**
     * The calendar link's ?data= param must be base64url (url-safe alphabet: no + or /).
     * Standard base64 can emit '+' (decoded to space in a query string) and '/' which
     * are both URL-unsafe. The fix switches to rtrim(strtr(base64_encode(...), '+/', '-_'), '=').
     */
    public function test_calendar_link_data_param_is_base64url_safe(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $this->createTryst($user1, $user2, [
            'arrangedfor' => now()->addDays(2)->setTime(14, 0)->format('Y-m-d H:i:s'),
            'icssent' => 0,
        ]);

        (new TrystService())->sendCalendarsDue(false);

        // Capture the calendarLink from one of the two sent mails
        $capturedLink = null;
        Mail::assertSent(TrystCalendarInviteMail::class, function (TrystCalendarInviteMail $mail) use (&$capturedLink) {
            $capturedLink = $mail->calendarLink;
            return true;
        });

        $this->assertNotNull($capturedLink, 'calendarLink was not set on the sent mail');

        // Extract the ?data= parameter
        $parsed = parse_url($capturedLink);
        parse_str($parsed['query'] ?? '', $queryParams);
        $dataParam = $queryParams['data'] ?? null;

        $this->assertNotNull($dataParam, 'No ?data= param in calendar link: ' . $capturedLink);

        // Must not contain standard base64 URL-unsafe characters
        $this->assertStringNotContainsString('+', $dataParam, 'data param contains + (URL-unsafe): ' . $dataParam);
        $this->assertStringNotContainsString('/', $dataParam, 'data param contains / (URL-unsafe): ' . $dataParam);

        // Must be valid base64url (only A-Z a-z 0-9 - _)
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $dataParam, 'data param is not valid base64url: ' . $dataParam);

        // Must round-trip to JSON with the expected event fields
        // base64url → standard base64 → decode → JSON
        $padded = $dataParam . str_repeat('=', (4 - strlen($dataParam) % 4) % 4);
        $standard = strtr($padded, '-_', '+/');
        $decoded  = base64_decode($standard, strict: true);

        $this->assertNotFalse($decoded, 'base64url decode failed for: ' . $dataParam);

        $event = json_decode($decoded, associative: true);

        $this->assertNotNull($event, 'JSON decode failed: ' . $decoded);
        $this->assertArrayHasKey('name', $event);
        $this->assertArrayHasKey('startDate', $event);
        $this->assertArrayHasKey('startTime', $event);
        $this->assertArrayHasKey('endTime', $event);
        $this->assertArrayHasKey('timeZone', $event);
        $this->assertStringStartsWith('Handover:', $event['name']);
    }
}
