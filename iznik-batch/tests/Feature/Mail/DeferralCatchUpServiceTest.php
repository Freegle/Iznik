<?php

namespace Tests\Feature\Mail;

use App\Mail\Deferrals\UnreadChatCatchUpMail;
use App\Models\ChatRoom;
use App\Services\Mail\Deferrals\DeferralCatchUpService;
use App\Services\Mail\MailSuppressionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The promise this feature makes to a member is "we held your mail, and when
 * it cleared we sent you ONE thing" - not "we sent you the nine emails you
 * missed, all at once". These tests are about keeping that promise.
 */
class DeferralCatchUpServiceTest extends TestCase
{
    private DeferralCatchUpService $catchUp;

    private MailSuppressionService $suppressions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->suppressions = new MailSuppressionService;
        $this->suppressions->flushCache();
        $this->catchUp = new DeferralCatchUpService($this->suppressions);
    }

    private function owe(int $userId, string $type, int $count = 9, ?int $suppressionId = null): void
    {
        DB::table('mail_suppressed_counts')->insert([
            'userid' => $userId,
            'emailtype' => $type,
            'suppressionid' => $suppressionId,
            'count' => $count,
            'firstat' => '2026-08-15 16:38:00',
            'lastat' => now(),
        ]);
    }

    private function suppressDomain(string $domain): int
    {
        $id = DB::table('mail_suppressions')->insertGetId([
            'scope' => 'domain',
            'value' => $domain,
            'provider' => 'Yahoo',
            'reason' => '421 4.7.0 [TSS04] temporarily deferred',
            'deferred_since' => '2026-08-15 16:38:00',
            'first_seen' => now(),
            'last_seen' => now(),
        ]);
        $this->suppressions->flushCache();

        return $id;
    }

    public function test_sends_one_summary_for_unread_chats_not_one_per_message(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($other, $member);

        // Nine messages they were never emailed about - the shape of the real
        // incident, where members held around nine queued messages each.
        for ($i = 0; $i < 9; $i++) {
            $this->createTestChatMessage($room, $other);
        }

        $this->owe($member->id, 'chat', 9);

        $result = $this->catchUp->run();

        $this->assertSame(1, $result['sent']);
        Mail::assertSent(UnreadChatCatchUpMail::class, 1);
        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->chatCount === 1 && $mail->messageCount === 9;
        });
    }

    public function test_counts_distinct_chats_not_just_messages(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $this->createTestChatMessage($this->createTestChatRoom($a, $member), $a);
        $this->createTestChatMessage($this->createTestChatRoom($b, $member), $b);

        $this->owe($member->id, 'chat', 2);

        $this->catchUp->run();

        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->chatCount === 2 && $mail->messageCount === 2;
        });
    }

    public function test_does_not_count_the_members_own_messages(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($other, $member);
        $this->createTestChatMessage($room, $member);

        $this->owe($member->id, 'chat', 1);

        $result = $this->catchUp->run();

        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_sends_nothing_when_they_have_caught_up_in_the_meantime(): void
    {
        // Read everything already, or the other side gave up. No email is the
        // right email.
        Mail::fake();

        $member = $this->createTestUser();
        $this->owe($member->id, 'chat', 5);

        $result = $this->catchUp->run();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['dropped']);
        Mail::assertNothingSent();
    }

    public function test_drops_stale_post_and_newsletter_mail_rather_than_replaying_it(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $this->owe($member->id, 'digest_immediate', 52);
        $this->owe($member->id, 'communitynews', 1);
        $this->owe($member->id, 'engage', 1);

        $result = $this->catchUp->run();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(3, $result['dropped']);
        Mail::assertNothingSent();
    }

    public function test_daily_digests_are_not_replayed_here(): void
    {
        // The digest catch-up is structural, not a replay: the gate returns
        // before the digest tracker is advanced, so the next daily run spans
        // the gap by itself. Sending one from here as well would double up.
        Mail::fake();

        $member = $this->createTestUser();
        $this->owe($member->id, 'digest_daily', 3);

        $result = $this->catchUp->run();

        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_waits_while_the_member_is_still_suppressed(): void
    {
        Mail::fake();

        $member = $this->createTestUser(['email_preferred' => 'held' . uniqid() . '@yahoo.co.uk']);
        $other = $this->createTestUser();
        $this->createTestChatMessage($this->createTestChatRoom($other, $member), $other);

        $this->owe($member->id, 'chat', 4);
        $this->suppressDomain('yahoo.co.uk');

        $result = $this->catchUp->run();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        Mail::assertNothingSent();

        // The debt must still be owed, not quietly written off.
        $this->assertDatabaseHas('mail_suppressed_counts', [
            'userid' => $member->id, 'caughtup_at' => null,
        ]);
    }

    public function test_never_sends_the_same_catch_up_twice(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $this->createTestChatMessage($this->createTestChatRoom($other, $member), $other);
        $this->owe($member->id, 'chat', 4);

        $this->assertSame(1, $this->catchUp->run()['sent']);
        $this->assertSame(0, $this->catchUp->run()['sent']);

        Mail::assertSent(UnreadChatCatchUpMail::class, 1);
    }

    public function test_dry_run_reports_without_sending_or_claiming(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $this->createTestChatMessage($this->createTestChatRoom($other, $member), $other);
        $this->owe($member->id, 'chat', 4);

        $result = $this->catchUp->run(dryRun: true);

        $this->assertSame(1, $result['sent']);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('mail_suppressed_counts', [
            'userid' => $member->id, 'caughtup_at' => null,
        ]);
    }

    public function test_clears_the_debt_for_a_member_with_no_usable_address(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        DB::table('users_emails')->where('userid', $member->id)->delete();
        $this->owe($member->id, 'chat', 4);

        $result = $this->catchUp->run();

        $this->assertSame(1, $result['dropped']);
        $this->assertDatabaseMissing('mail_suppressed_counts', [
            'userid' => $member->id, 'caughtup_at' => null,
        ]);
    }

    public function test_the_summary_names_the_provider_and_the_date_it_started(): void
    {
        Mail::fake();

        $member = $this->createTestUser(['email_preferred' => 'held' . uniqid() . '@yahoo.co.uk']);
        $other = $this->createTestUser();
        $this->createTestChatMessage($this->createTestChatRoom($other, $member), $other);
        // Released, so the gate is open, but the suppression we recorded at
        // the time is still there to name who was refusing us.
        $id = DB::table('mail_suppressions')->insertGetId([
            'scope' => 'domain', 'value' => 'yahoo.co.uk', 'provider' => 'Yahoo',
            'reason' => '421', 'deferred_since' => '2026-08-15 16:38:00',
            'first_seen' => now(), 'last_seen' => now(), 'released_at' => now(),
        ]);
        $this->suppressions->flushCache();

        $this->owe($member->id, 'chat', 4, $id);

        $this->catchUp->run();

        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->provider === 'Yahoo' && $mail->delayedSince === '15 August';
        });
    }

    public function test_chat_room_type_does_not_matter_to_the_summary(): void
    {
        // A member waiting on a mod reply is as stranded as one waiting on
        // another member.
        Mail::fake();

        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($other, $member, ['chattype' => ChatRoom::TYPE_USER2MOD]);
        $this->createTestChatMessage($room, $other);
        $this->owe($member->id, 'chat', 1);

        $this->assertSame(1, $this->catchUp->run()['sent']);
    }
}
