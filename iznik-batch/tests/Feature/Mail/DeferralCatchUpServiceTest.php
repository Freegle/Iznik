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

    /**
     * Put both people on the chat's roster.
     *
     * chat_roster is where the per-(chat, member) email watermark lives, and
     * ChatNotificationService only ever notifies members who have a row - so
     * a member with no row would never have been emailed, and would have
     * nothing to catch up on. Creating them here keeps the fixture honest
     * about that rather than testing a shape production never produces.
     */
    private function roster($room, $user1, $user2): void
    {
        foreach ([$user1->id, $user2->id] as $uid) {
            DB::table('chat_roster')->insertOrIgnore([
                'chatid' => $room->id,
                'userid' => $uid,
                'date' => now(),
                'status' => 'Online',
            ]);
        }
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

        $this->roster($room, $other, $member);

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

    /**
     * The count is what they MISSED and have not read - not everything we never
     * happened to email them about.
     *
     * chat_roster.lastmsgemailed records what we have EMAILED. A member who
     * reads on the website is rarely emailed, so it sits far behind for ever,
     * and where it is NULL the whole chat counts from its first message.
     * Measured on prod 2026-08-22, member 420 was emailed "1,247 messages
     * across 7 chats" when he had 8 unread in total, none of them from the
     * outage, and the oldest message counted was from January 2019.
     */
    public function test_does_not_count_old_messages_the_member_has_already_read(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($other, $member);
        $this->roster($room, $other, $member);

        // A long history, all read on the website, none of it emailed.
        $lastRead = null;
        for ($i = 0; $i < 12; $i++) {
            $lastRead = $this->createTestChatMessage($room, $other);
        }
        // Directly by chatid: MySQL refuses an UPDATE whose subquery reads the same
        // table (error 1093), so the self-referencing whereIn form errors on every
        // run. The subquery selected nothing the WHERE cannot say alone.
        DB::table('chat_messages')->where('chatid', $room->id)
            ->update(['date' => '2019-01-16 10:00:00']);

        // Seen everything; never emailed about any of it.
        DB::table('chat_roster')->where('chatid', $room->id)->where('userid', $member->id)
            ->update(['lastmsgseen' => $lastRead->id, 'lastmsgemailed' => null]);

        // Two that arrived during the outage and are genuinely unread.
        $this->createTestChatMessage($room, $other);
        $this->createTestChatMessage($room, $other);

        $this->owe($member->id, 'chat', 2);

        $this->catchUp->run();

        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->messageCount === 2 && $mail->chatCount === 1;
        });
    }

    /**
     * The email says "while it was going on you had N messages", so anything
     * from before the provider started refusing us is a different number.
     */
    public function test_counts_only_what_arrived_during_the_outage(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($other, $member);
        $this->roster($room, $other, $member);

        // Unread, but from well before the outage began (owe() dates it 08-15).
        $old = $this->createTestChatMessage($room, $other);
        DB::table('chat_messages')->where('id', $old->id)
            ->update(['date' => '2026-07-01 09:00:00']);

        $this->createTestChatMessage($room, $other);

        $this->owe($member->id, 'chat', 1);

        $this->catchUp->run();

        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->messageCount === 1;
        });
    }

    /**
     * Nothing unread from the outage means no email. Telling somebody we held
     * their messages back and then showing them an empty inbox is worse than
     * saying nothing.
     */
    public function test_sends_nothing_when_the_outage_cost_them_nothing(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($other, $member);
        $this->roster($room, $other, $member);

        $read = $this->createTestChatMessage($room, $other);
        DB::table('chat_roster')->where('chatid', $room->id)->where('userid', $member->id)
            ->update(['lastmsgseen' => $read->id, 'lastmsgemailed' => null]);

        $this->owe($member->id, 'chat', 1);

        $result = $this->catchUp->run();

        $this->assertSame(0, $result['sent']);
        Mail::assertNotSent(UnreadChatCatchUpMail::class);
    }

    public function test_counts_distinct_chats_not_just_messages(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $room = $this->createTestChatRoom($a, $member);
        $this->roster($room, $a, $member);
        $this->createTestChatMessage($room, $a);
        $room = $this->createTestChatRoom($b, $member);
        $this->roster($room, $b, $member);
        $this->createTestChatMessage($room, $b);

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
        $this->roster($room, $other, $member);
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
        $room = $this->createTestChatRoom($other, $member);
        $this->roster($room, $other, $member);
        $this->createTestChatMessage($room, $other);

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
        $room = $this->createTestChatRoom($other, $member);
        $this->roster($room, $other, $member);
        $this->createTestChatMessage($room, $other);
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
        $room = $this->createTestChatRoom($other, $member);
        $this->roster($room, $other, $member);
        $this->createTestChatMessage($room, $other);
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
        $room = $this->createTestChatRoom($other, $member);
        $this->roster($room, $other, $member);
        $this->createTestChatMessage($room, $other);
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

    /**
     * A member who wrote to a group's volunteers is on the member side of a
     * User2Mod chat (user1), and reads the reply on the member site like any
     * other chat. Waiting on a mod is as stranded as waiting on a member.
     */
    public function test_a_member_writing_to_the_volunteers_is_on_the_member_side(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $mod = $this->createTestUser();
        $room = $this->createTestChatRoom($member, $mod, ['chattype' => ChatRoom::TYPE_USER2MOD]);
        $this->roster($room, $member, $mod);
        $this->createTestChatMessage($room, $mod);
        $this->owe($member->id, 'chat', 1);

        $this->assertSame(1, $this->catchUp->run()['sent']);
        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->chatCount === 1 && $mail->messageCount === 1
                && $mail->modChatCount === 0 && $mail->modMessageCount === 0;
        });
    }

    /**
     * A moderator is on the roster of every User2Mod chat on their groups, on
     * the volunteers' side. Those chats are only visible in ModTools: the
     * member site lists User2Mod chats where the viewer IS the member, so
     * pointing a moderator at the member site shows them nothing and the
     * email looks like a lie.
     *
     * Found 2026-09-12: a moderator on Virgin Media was told "a message in one
     * chat", clicked through to the member site, and saw only a two-month-old
     * chat of her own. The message was a member writing to her group's
     * volunteers.
     */
    public function test_a_moderators_chats_with_members_are_counted_on_the_mod_side(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $member = $this->createTestUser();
        $room = $this->createTestChatRoom($member, $mod, ['chattype' => ChatRoom::TYPE_USER2MOD]);
        $this->roster($room, $member, $mod);
        $this->createTestChatMessage($room, $member);
        $this->owe($mod->id, 'chat', 1);

        $this->assertSame(1, $this->catchUp->run()['sent']);
        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->chatCount === 0 && $mail->messageCount === 0
                && $mail->modChatCount === 1 && $mail->modMessageCount === 1;
        });
    }

    public function test_mod_to_mod_chats_are_on_the_mod_side(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $otherMod = $this->createTestUser();
        $room = $this->createTestChatRoom($otherMod, $mod, ['chattype' => ChatRoom::TYPE_MOD2MOD]);
        $this->roster($room, $otherMod, $mod);
        $this->createTestChatMessage($room, $otherMod);
        $this->createTestChatMessage($room, $otherMod);
        $this->owe($mod->id, 'chat', 2);

        $this->assertSame(1, $this->catchUp->run()['sent']);
        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->chatCount === 0 && $mail->modChatCount === 1 && $mail->modMessageCount === 2;
        });
    }

    /**
     * Someone who is both a member and a moderator gets one email with both
     * halves, each pointing where that half can actually be read.
     */
    public function test_member_and_mod_side_chats_are_reported_separately(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        $friend = $this->createTestUser();
        $member = $this->createTestUser();

        $personal = $this->createTestChatRoom($friend, $mod);
        $this->roster($personal, $friend, $mod);
        $this->createTestChatMessage($personal, $friend);

        $volunteers = $this->createTestChatRoom($member, $mod, ['chattype' => ChatRoom::TYPE_USER2MOD]);
        $this->roster($volunteers, $member, $mod);
        $this->createTestChatMessage($volunteers, $member);
        $this->createTestChatMessage($volunteers, $member);

        $this->owe($mod->id, 'chat', 3);

        $this->assertSame(1, $this->catchUp->run()['sent']);
        Mail::assertSent(UnreadChatCatchUpMail::class, 1);
        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->chatCount === 1 && $mail->messageCount === 1
                && $mail->modChatCount === 1 && $mail->modMessageCount === 2;
        });
    }

    /**
     * "Stopped accepting our emails on X" is about the provider, so X is when
     * the provider started refusing us - not when we first happened to have
     * something to hold back for this member, which can be a day or more
     * later and, read alongside the send date, makes the email look wrong.
     *
     * Found 2026-09-12: Virgin Media began refusing us at 06:01 on the 10th;
     * a member whose first held mail was on the 11th was told "stopped
     * accepting our emails on 11 September" in an email sent on 11 September.
     */
    public function test_the_date_is_when_the_provider_started_refusing_not_when_we_first_held_their_mail(): void
    {
        Mail::fake();

        $member = $this->createTestUser(['email_preferred' => 'held' . uniqid() . '@yahoo.co.uk']);
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($other, $member);
        $this->roster($room, $other, $member);
        $this->createTestChatMessage($room, $other);
        $id = DB::table('mail_suppressions')->insertGetId([
            'scope' => 'domain', 'value' => 'yahoo.co.uk', 'provider' => 'Yahoo',
            'reason' => '421', 'deferred_since' => '2026-08-14 06:01:00',
            'first_seen' => now(), 'last_seen' => now(), 'released_at' => now(),
        ]);
        $this->suppressions->flushCache();

        // owe() records our first hold for this member as 2026-08-15.
        $this->owe($member->id, 'chat', 4, $id);

        $this->catchUp->run();

        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->delayedSince === '14 August';
        });
    }

    public function test_falls_back_to_our_first_hold_when_no_suppression_was_recorded(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $room = $this->createTestChatRoom($other, $member);
        $this->roster($room, $other, $member);
        $this->createTestChatMessage($room, $other);
        $this->owe($member->id, 'chat', 1);

        $this->catchUp->run();

        Mail::assertSent(UnreadChatCatchUpMail::class, function ($mail) {
            return $mail->delayedSince === '15 August' && $mail->provider === null;
        });
    }
}
