<?php

namespace Tests\Unit\Services;

use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Services\ChatNotificationService;
use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ChatNotificationService::notifyByEmail under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.7). The ordinary (no lockdown)
 * behaviour is covered by ChatNotificationServiceTest; this file is only the lockdown
 * branch: proving that a recipient is skipped, before any mail is built, WITHOUT
 * chat_roster.lastmsgemailed advancing, so nothing is lost, and that lifting the surface
 * delivers one normal notification covering what built up while it was held.
 *
 * This is a generating-loop check (LockdownService::held('email'), counted
 * deferred:chat), not the old MailSuppressionService::shouldSkip()-driven skip -
 * shouldSkip() has no lockdown branch any more (see MailSuppressionServiceLockdownTest).
 */
class ChatNotificationServiceLockdownTest extends TestCase
{
    private ChatNotificationService $service;

    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        DB::table('lockdown_acks')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
        $this->service = new ChatNotificationService();
        Mail::fake();
    }

    public function test_held_recipient_is_skipped_without_advancing_the_watermark(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $this->lockdown->press(null, 'test lockdown');

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        $this->assertSame(0, $count);
        Mail::assertNothingSent();

        // The watermark must be untouched - not just "not advanced past this message"
        // but genuinely null, exactly as it was before the message existed. That is
        // what lets the same query pick this message up again once email resumes.
        $recipientRoster = ChatRoster::where('chatid', $room->id)->where('userid', $recipient->id)->first();
        $this->assertNull($recipientRoster->lastmsgemailed);

        // Counted as deferred, per section 11.7 - never in mail_suppressed_counts
        // (that table is for provider bounces, not for a lockdown hold).
        $counter = DB::table('lockdown_counters')->where('kind', 'deferred:chat')->first();
        $this->assertNotNull($counter);
        $this->assertSame(1, (int) $counter->count);
        $this->assertSame(0, DB::table('mail_suppressed_counts')->count());

        // The loop still acks so the presser can see chat notification handling has
        // taken effect, even though the ack moved out of shouldSkip and into this
        // loop's own held() check.
        $ack = DB::table('lockdown_acks')->where('loop', 'mail-loops')->first();
        $this->assertNotNull($ack);
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }

    public function test_a_recipient_with_notifications_off_is_neither_deferred_nor_counted(): void
    {
        // shouldNotifyUser() runs before the lockdown check (same reasoning as the
        // suppression check that follows it): a member who was never going to be
        // mailed is not "deferred" by the lockdown - there is nothing held back for
        // them, and counting them would overstate how much mail is waiting.
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser(['settings' => ['notifications' => ['email' => false]]]);

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create(['chatid' => $room->id, 'userid' => $sender->id, 'lastmsgemailed' => null]);
        ChatRoster::create(['chatid' => $room->id, 'userid' => $recipient->id, 'lastmsgemailed' => null]);

        $this->createTestChatMessage($room, $sender, ['date' => now()->subMinutes(5)]);

        $this->lockdown->press(null, 'test lockdown');

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DB::table('lockdown_counters')->where('kind', 'deferred:chat')->count());
    }

    public function test_lifting_email_sends_every_message_that_built_up_while_held(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        $this->lockdown->press(null, 'test lockdown');

        // Two messages arrive while email is held. Neither is ever mailed individually.
        // Distinct text matters here: getUnmailedMessages() collapses rows that share
        // chatid+userid+type+message+refmsgid, to fold a double-submit ~1s apart into one
        // send (see its docblock). Two genuinely separate messages must not look like that,
        // or the second is silently dropped rather than deferred.
        $this->createTestChatMessage($room, $sender, ['date' => now()->subMinutes(10), 'message' => 'First message held']);
        $latest = $this->createTestChatMessage($room, $sender, ['date' => now()->subMinutes(5), 'message' => 'Second message held']);

        $heldCount = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);
        $this->assertSame(0, $heldCount);
        Mail::assertNothingSent();

        $this->lockdown->setSurfaces(['email' => false], null);

        // notifyByEmail sends each message individually (its own docblock: "Simplified:
        // sends each message individually (no batching)") - that is pre-existing, unrelated
        // to lockdown, and section 11.7 never asks for batching, only for not losing mail.
        // So a run once email resumes finds both still-unmailed messages and sends one
        // notification per message, in order, exactly as it would after any other gap.
        $sentCount = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);
        $this->assertSame(2, $sentCount);

        $recipientRoster = ChatRoster::where('chatid', $room->id)->where('userid', $recipient->id)->first();
        $this->assertEquals($latest->id, $recipientRoster->lastmsgemailed);
    }
}
