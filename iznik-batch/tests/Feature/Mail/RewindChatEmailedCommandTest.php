<?php

namespace Tests\Feature\Mail;

use App\Mail\Deferrals\UnreadChatCatchUpMail;
use App\Services\Mail\Deferrals\DeferralCatchUpService;
use App\Services\Mail\MailSuppressionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * mail:deferrals:rewind-chat exists so that purging the relay queue does not
 * strand a member who was waiting on a reply. The debt row it writes is what
 * makes the release catch-up send them one "you have unread messages" summary.
 */
class RewindChatEmailedCommandTest extends TestCase
{
    public function test_a_member_whose_chat_notification_was_purged_gets_the_catch_up_summary(): void
    {
        Mail::fake();

        $domain = 'rewind' . uniqid() . '.example';
        $suppressions = new MailSuppressionService;
        $suppressions->flushCache();

        $member = $this->createTestUser(['email_preferred' => 'held' . uniqid() . '@' . $domain]);
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($other, $member);

        // One message from three days ago that the member has not read. We
        // generated the notification for it, so lastmsgemailed has moved past
        // it, but the relay never delivered it.
        $message = $this->createTestChatMessage($room, $other, ['date' => now()->subDays(3)]);

        foreach ([$other->id, $member->id] as $uid) {
            DB::table('chat_roster')->insertOrIgnore([
                'chatid' => $room->id,
                'userid' => $uid,
                'date' => now(),
                'status' => 'Online',
            ]);
        }

        DB::table('chat_roster')->where('chatid', $room->id)->where('userid', $member->id)
            ->update(['lastmsgseen' => null, 'lastmsgemailed' => $message->id]);

        DB::table('email_tracking')->insert([
            'tracking_id' => bin2hex(random_bytes(8)),
            'email_type' => 'ChatNotification',
            'userid' => $member->id,
            'recipient_email' => $member->email_preferred,
            'sent_at' => now()->subDays(2),
            'metadata' => json_encode(['chat_id' => $room->id, 'message_id' => $message->id]),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $suppressionId = DB::table('mail_suppressions')->insertGetId([
            'scope' => 'domain',
            'value' => $domain,
            'provider' => 'Yahoo',
            'reason' => '421 4.7.0 temporarily deferred',
            'deferred_since' => now()->subDays(4),
            'first_seen' => now()->subDays(4),
            'last_seen' => now(),
        ]);
        $suppressions->flushCache();

        $this->artisan('mail:deferrals:rewind-chat', [
            '--start' => now()->subDays(5)->format('Y-m-d H:i:s'),
            '--end' => now()->addMinute()->format('Y-m-d H:i:s'),
            '--domains' => $domain,
        ])->assertExitCode(0);

        // The command did its part: a debt is owed.
        $this->assertDatabaseHas('mail_suppressed_counts', [
            'userid' => $member->id,
            'emailtype' => 'chat',
            'suppressionid' => $suppressionId,
            'caughtup_at' => null,
        ]);

        // The provider recovers and the suppression is released.
        DB::table('mail_suppressions')->where('id', $suppressionId)->update(['released_at' => now()]);
        $suppressions->flushCache();

        $result = (new DeferralCatchUpService($suppressions))->run();

        $this->assertSame(1, $result['sent'], 'The member should be told about the message they never received.');
        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->chatCount === 1 && $mail->messageCount === 1;
        });
    }
}
