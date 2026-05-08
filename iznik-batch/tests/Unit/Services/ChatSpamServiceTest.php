<?php

namespace Tests\Unit\Services;

use App\Mail\Chat\SpamWarningMail;
use App\Models\ChatRoom;
use App\Services\ChatSpamService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChatSpamServiceTest extends TestCase
{
    protected ChatSpamService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatSpamService();
    }

    // -------------------------------------------------------------------------
    // warnInnocentUsers
    // -------------------------------------------------------------------------

    public function test_warn_innocent_users_sends_warning_email(): void
    {
        $spammer = $this->createTestUser();
        $innocent = $this->createTestUser();
        $room = $this->createTestChatRoom($spammer, $innocent, [
            'latestmessage' => now()->subDays(3),
            'flaggedspam'   => 0,
        ]);

        DB::table('spam_users')->insert([
            'userid'     => $spammer->id,
            'collection' => 'Spammer',
            'added'      => now(),
        ]);

        // Create a visible message in the room
        $this->createTestChatMessage($room, $spammer, [
            'reviewrequired'      => 0,
            'reviewrejected'      => 0,
            'processingsuccessful' => 1,
        ]);

        Mail::fake();

        $sent = $this->service->warnInnocentUsers();

        $this->assertEquals(1, $sent);
        Mail::assertSent(SpamWarningMail::class, function ($mail) use ($innocent) {
            return $mail->recipient->id === $innocent->id;
        });

        $this->assertEquals(1, $room->fresh()->flaggedspam);
    }

    public function test_warn_innocent_users_skips_already_flagged_rooms(): void
    {
        $spammer = $this->createTestUser();
        $innocent = $this->createTestUser();
        $room = $this->createTestChatRoom($spammer, $innocent, [
            'latestmessage' => now()->subDays(3),
            'flaggedspam'   => 1,
        ]);

        DB::table('spam_users')->insert([
            'userid'     => $spammer->id,
            'collection' => 'Spammer',
            'added'      => now(),
        ]);

        $this->createTestChatMessage($room, $spammer);

        Mail::fake();

        $sent = $this->service->warnInnocentUsers();

        $this->assertEquals(0, $sent);
    }

    public function test_warn_innocent_users_skips_rooms_with_no_visible_messages(): void
    {
        $spammer = $this->createTestUser();
        $innocent = $this->createTestUser();
        $room = $this->createTestChatRoom($spammer, $innocent, [
            'latestmessage' => now()->subDays(3),
            'flaggedspam'   => 0,
        ]);

        DB::table('spam_users')->insert([
            'userid'     => $spammer->id,
            'collection' => 'Spammer',
            'added'      => now(),
        ]);

        // Message held for review — not visible
        $this->createTestChatMessage($room, $spammer, [
            'reviewrequired'      => 1,
            'reviewrejected'      => 0,
            'processingsuccessful' => 0,
        ]);

        Mail::fake();

        $sent = $this->service->warnInnocentUsers();

        $this->assertEquals(0, $sent);
    }

    public function test_warn_innocent_users_skips_old_rooms(): void
    {
        $spammer = $this->createTestUser();
        $innocent = $this->createTestUser();
        $room = $this->createTestChatRoom($spammer, $innocent, [
            'latestmessage' => now()->subDays(10),
            'flaggedspam'   => 0,
        ]);

        DB::table('spam_users')->insert([
            'userid'     => $spammer->id,
            'collection' => 'Spammer',
            'added'      => now(),
        ]);

        $this->createTestChatMessage($room, $spammer);

        Mail::fake();

        $sent = $this->service->warnInnocentUsers();

        $this->assertEquals(0, $sent);
    }

    public function test_warn_innocent_users_identifies_innocent_when_user2_is_spammer(): void
    {
        $innocent = $this->createTestUser();
        $spammer = $this->createTestUser();

        // Room where innocent=user1, spammer=user2
        $room = $this->createTestChatRoom($innocent, $spammer, [
            'latestmessage' => now()->subDays(3),
            'flaggedspam'   => 0,
        ]);

        DB::table('spam_users')->insert([
            'userid'     => $spammer->id,
            'collection' => 'Spammer',
            'added'      => now(),
        ]);

        $this->createTestChatMessage($room, $spammer, [
            'reviewrequired'      => 0,
            'reviewrejected'      => 0,
            'processingsuccessful' => 1,
        ]);

        Mail::fake();

        $sent = $this->service->warnInnocentUsers();

        $this->assertEquals(1, $sent);
        Mail::assertSent(SpamWarningMail::class, function ($mail) use ($innocent) {
            return $mail->recipient->id === $innocent->id;
        });
    }

    // -------------------------------------------------------------------------
    // autoMarkSpam
    // -------------------------------------------------------------------------

    public function test_auto_mark_spam_marks_pending_messages_for_high_rejection_users(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $other);

        // 6 rejected messages
        for ($i = 0; $i < 6; $i++) {
            $this->createTestChatMessage($room, $user, [
                'date'                => now()->subDays(5),
                'reviewrequired'      => 0,
                'processingsuccessful' => 0,
                'reviewrejected'      => 1,
            ]);
        }

        // 1 pending message
        $pending = $this->createTestChatMessage($room, $user, [
            'date'                => now()->subDays(1),
            'reviewrequired'      => 1,
            'processingrequired'  => 1,
            'processingsuccessful' => 0,
            'reviewrejected'      => 0,
        ]);

        $count = $this->service->autoMarkSpam();

        $this->assertEquals(1, $count);

        $refreshed = $pending->fresh();
        $this->assertEquals(1, $refreshed->reviewrejected);
        $this->assertEquals(0, $refreshed->reviewrequired);
    }

    public function test_auto_mark_spam_skips_users_with_few_rejections(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $other);

        // Only 3 rejected messages — below threshold of 5
        for ($i = 0; $i < 3; $i++) {
            $this->createTestChatMessage($room, $user, [
                'reviewrequired'      => 0,
                'processingsuccessful' => 0,
                'reviewrejected'      => 1,
            ]);
        }

        $count = $this->service->autoMarkSpam();

        $this->assertEquals(0, $count);
    }

    public function test_auto_mark_spam_skips_users_with_previously_approved_message(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($user, $other);

        // 6 rejected messages
        for ($i = 0; $i < 6; $i++) {
            $this->createTestChatMessage($room, $user, [
                'reviewrequired'      => 0,
                'processingsuccessful' => 0,
                'reviewrejected'      => 1,
            ]);
        }

        // 1 previously approved message (reviewedby is set, reviewrejected=0)
        $reviewer = $this->createTestUser();
        $this->createTestChatMessage($room, $user, [
            'reviewrequired'      => 1,
            'processingsuccessful' => 1,
            'reviewrejected'      => 0,
            'reviewedby'          => $reviewer->id,
        ]);

        $count = $this->service->autoMarkSpam();

        $this->assertEquals(0, $count);
    }
}
