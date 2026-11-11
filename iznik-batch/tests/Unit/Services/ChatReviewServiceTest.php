<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Services\ChatReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Unit coverage for {@see ChatReviewService}: the auto-reject of chat messages
 * stuck in review for 7+ days, and the 48 hour nudge to group mods.
 *
 * Mail::raw() is a no-op under Mail::fake(), so these tests read what the
 * "array" mailer (MAIL_MAILER in phpunit.xml) actually received instead.
 *
 * The test database is not empty mid-suite, so assertions about counts are
 * scoped to the fixtures built here (by message id, group name or subject)
 * rather than to the totals.
 */
class ChatReviewServiceTest extends TestCase
{
    private ChatReviewService $service;

    private string $modbotEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatReviewService();

        // A unique address so another test's modbot cannot be picked up.
        $this->modbotEmail = $this->uniqueEmail('modbot');
        config(['freegle.mail.moderator_email' => $this->modbotEmail]);
        config(['freegle.mail.support_addr' => 'support-test@example.org']);
        config(['freegle.mail.mentors_addr' => 'mentors-test@example.org']);
        config(['freegle.mail.group_domain' => 'groups.example.org']);
        config(['freegle.sites.mod' => 'https://mod.example.org']);

        Mail::mailer('array')->getSymfonyTransport()->flush();
    }

    private function sent(): array
    {
        return array_map(
            fn ($m) => $m->getOriginalMessage(),
            Mail::mailer('array')->getSymfonyTransport()->messages()->all()
        );
    }

    /** Mails whose subject names the given group. */
    private function sentFor(Group $group): array
    {
        return array_values(array_filter(
            $this->sent(),
            fn ($m) => str_contains($m->getSubject(), $group->nameshort)
        ));
    }

    private function createModbot(array $attributes = []): int
    {
        $modbot = $this->createTestUser($attributes);
        DB::table('users_emails')->where('userid', $modbot->id)->update(['email' => $this->modbotEmail]);

        return $modbot->id;
    }

    /** A pending chat message; the recipient (not the sender) carries the memberships. */
    private function pendingMessage(string $age = '-3 days', array $attrs = [], array $senderAttrs = []): array
    {
        $sender = $this->createTestUser($senderAttrs);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $message = $this->createTestChatMessage($room, $sender, array_merge([
            'date' => date('Y-m-d H:i:s', strtotime($age)),
            'reviewrequired' => 1,
            'reviewedby' => null,
            'reviewrejected' => 0,
        ], $attrs));

        return [$sender, $recipient, $room, $message];
    }

    private function row(int $id): object
    {
        return DB::table('chat_messages')->where('id', $id)->first();
    }

    // ---- auto-reject -----------------------------------------------------

    public function test_stale_message_is_rejected_by_modbot(): void
    {
        $modbotId = $this->createModbot();
        [, , , $message] = $this->pendingMessage('-8 days');

        $result = $this->service->processReview();

        $this->assertGreaterThanOrEqual(1, $result['rejected']);
        $row = $this->row($message->id);
        $this->assertSame($modbotId, (int) $row->reviewedby);
        $this->assertSame(1, (int) $row->reviewrejected);
    }

    public function test_nothing_rejected_when_modbot_user_does_not_exist(): void
    {
        [, , , $message] = $this->pendingMessage('-8 days');

        $result = $this->service->processReview();

        $this->assertSame(0, $result['rejected']);
        $row = $this->row($message->id);
        $this->assertNull($row->reviewedby);
        $this->assertSame(0, (int) $row->reviewrejected);
    }

    public function test_deleted_modbot_user_is_ignored(): void
    {
        $this->createModbot(['deleted' => now()]);
        [, , , $message] = $this->pendingMessage('-8 days');

        $result = $this->service->processReview();

        $this->assertSame(0, $result['rejected']);
        $this->assertNull($this->row($message->id)->reviewedby);
    }

    public function test_dry_run_counts_stale_messages_without_changing_them(): void
    {
        $this->createModbot();
        [, , , $message] = $this->pendingMessage('-8 days');

        $result = $this->service->processReview(true);

        $this->assertGreaterThanOrEqual(1, $result['rejected']);
        $row = $this->row($message->id);
        $this->assertNull($row->reviewedby);
        $this->assertSame(0, (int) $row->reviewrejected);
    }

    public static function notStaleProvider(): array
    {
        return [
            'only 3 days old' => ['-3 days', ['reviewrequired' => 1]],
            'just under 7 days' => ['-6 days', ['reviewrequired' => 1]],
            'review not required' => ['-9 days', ['reviewrequired' => 0]],
        ];
    }

    #[DataProvider('notStaleProvider')]
    public function test_messages_that_are_not_stale_are_left_alone(string $age, array $attrs): void
    {
        $this->createModbot();
        [, , , $message] = $this->pendingMessage($age, $attrs);

        $this->service->processReview();

        $row = $this->row($message->id);
        $this->assertNull($row->reviewedby);
        $this->assertSame(0, (int) $row->reviewrejected);
    }

    public function test_already_reviewed_message_is_not_taken_over_by_modbot(): void
    {
        $modbotId = $this->createModbot();
        $mod = $this->createTestUser();
        [, , , $message] = $this->pendingMessage('-9 days', ['reviewedby' => $mod->id]);

        $this->service->processReview();

        $row = $this->row($message->id);
        $this->assertSame($mod->id, (int) $row->reviewedby);
        $this->assertNotSame($modbotId, (int) $row->reviewedby);
        $this->assertSame(0, (int) $row->reviewrejected);
    }

    // ---- notify mods -----------------------------------------------------

    public function test_group_is_emailed_at_contactmail_with_singular_subject_and_body(): void
    {
        [, $recipient] = $this->pendingMessage('-3 days');
        $group = $this->createTestGroup(['contactmail' => 'mods-' . uniqid() . '@example.org']);
        $this->createMembership($recipient, $group);

        $result = $this->service->processReview();

        $this->assertGreaterThanOrEqual(1, $result['notified_groups']);
        $this->assertGreaterThanOrEqual(1, $result['total_pending']);

        $mails = $this->sentFor($group);
        $this->assertCount(1, $mails);
        $mail = $mails[0];
        $this->assertSame(
            '1 message between members waiting for your review on ' . $group->nameshort,
            $mail->getSubject()
        );
        $this->assertSame($group->contactmail, $mail->getTo()[0]->getAddress());
        $this->assertSame('support-test@example.org', $mail->getFrom()[0]->getAddress());
        $this->assertSame('Freegle', $mail->getFrom()[0]->getName());
        $body = $mail->getTextBody();
        $this->assertStringContainsString("Dear {$group->nameshort} Volunteers,", $body);
        $this->assertStringContainsString('https://mod.example.org/chats/review', $body);
        $this->assertStringContainsString('mentors-test@example.org', $body);
    }

    public function test_plural_subject_when_several_messages_pending(): void
    {
        $group = $this->createTestGroup(['contactmail' => 'mods-' . uniqid() . '@example.org']);
        for ($i = 0; $i < 3; $i++) {
            [, $recipient] = $this->pendingMessage('-3 days');
            $this->createMembership($recipient, $group);
        }

        $this->service->processReview();

        $mails = $this->sentFor($group);
        $this->assertCount(1, $mails);
        $this->assertSame(
            '3 messages between members waiting for your review on ' . $group->nameshort,
            $mails[0]->getSubject()
        );
    }

    public function test_falls_back_to_volunteers_address_without_contactmail(): void
    {
        [, $recipient] = $this->pendingMessage('-3 days');
        $group = $this->createTestGroup(['contactmail' => null]);
        $this->createMembership($recipient, $group);

        $this->service->processReview();

        $mails = $this->sentFor($group);
        $this->assertCount(1, $mails);
        $this->assertSame(
            $group->nameshort . '-volunteers@groups.example.org',
            $mails[0]->getTo()[0]->getAddress()
        );
    }

    public function test_empty_contactmail_also_falls_back(): void
    {
        [, $recipient] = $this->pendingMessage('-3 days');
        $group = $this->createTestGroup(['contactmail' => '']);
        $this->createMembership($recipient, $group);

        $this->service->processReview();

        $mails = $this->sentFor($group);
        $this->assertCount(1, $mails);
        $this->assertSame(
            $group->nameshort . '-volunteers@groups.example.org',
            $mails[0]->getTo()[0]->getAddress()
        );
    }

    public static function excludedGroupProvider(): array
    {
        return [
            'not a Freegle group' => [['type' => Group::TYPE_OTHER]],
            'unpublished' => [['publish' => 0]],
        ];
    }

    #[DataProvider('excludedGroupProvider')]
    public function test_ineligible_groups_are_not_emailed(array $groupAttrs): void
    {
        [, $recipient] = $this->pendingMessage('-3 days');
        $group = $this->createTestGroup($groupAttrs);
        $this->createMembership($recipient, $group);

        $this->service->processReview();

        $this->assertSame([], $this->sentFor($group));
    }

    public static function excludedMessageProvider(): array
    {
        return [
            'under 48 hours old' => ['-10 hours', []],
            'review not required' => ['-3 days', ['reviewrequired' => 0]],
            'already rejected' => ['-3 days', ['reviewrejected' => 1]],
        ];
    }

    #[DataProvider('excludedMessageProvider')]
    public function test_messages_outside_the_window_do_not_trigger_mail(string $age, array $attrs): void
    {
        [, $recipient] = $this->pendingMessage($age, $attrs);
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);

        $this->service->processReview();

        $this->assertSame([], $this->sentFor($group));
    }

    public function test_message_reviewed_by_a_mod_does_not_trigger_mail(): void
    {
        $mod = $this->createTestUser();
        [, $recipient] = $this->pendingMessage('-3 days', ['reviewedby' => $mod->id]);
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);

        $this->service->processReview();

        $this->assertSame([], $this->sentFor($group));
    }

    public function test_held_message_does_not_trigger_mail(): void
    {
        [, $recipient, , $message] = $this->pendingMessage('-3 days');
        $holder = $this->createTestUser();
        DB::table('chat_messages_held')->insert(['msgid' => $message->id, 'userid' => $holder->id]);
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);

        $this->service->processReview();

        $this->assertSame([], $this->sentFor($group));
    }

    public function test_message_from_deleted_sender_does_not_trigger_mail(): void
    {
        [, $recipient] = $this->pendingMessage('-3 days', [], ['deleted' => now()]);
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);

        $this->service->processReview();

        $this->assertSame([], $this->sentFor($group));
    }

    public function test_group_is_chosen_from_the_recipients_memberships_not_the_senders(): void
    {
        [$sender] = $this->pendingMessage('-3 days');
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);

        $this->service->processReview();

        $this->assertSame([], $this->sentFor($group));
    }

    public function test_recipient_is_the_other_party_whichever_way_round_the_room_is(): void
    {
        // The author is user2 of the room, so the recipient is user1.
        $author = $this->createTestUser();
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($recipient, $author);
        $this->createTestChatMessage($room, $author, [
            'date' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'reviewrequired' => 1,
        ]);
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);

        $this->service->processReview();

        $this->assertCount(1, $this->sentFor($group));
    }

    public function test_orphaned_membership_group_is_skipped(): void
    {
        // A membership whose group row has gone must not break the run. Foreign
        // keys normally prevent this, so lift them for the insert only.
        [, $recipient] = $this->pendingMessage('-3 days');
        $goneId = (int) DB::table('groups')->max('id') + 100000;
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('memberships')->insert([
                'userid' => $recipient->id,
                'groupid' => $goneId,
                'role' => Membership::ROLE_MEMBER,
                'collection' => Membership::COLLECTION_APPROVED,
                'added' => now(),
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $result = $this->service->processReview();

        $this->assertIsInt($result['notified_groups']);
        $this->assertSame([], array_filter(
            $this->sent(),
            fn ($m) => str_contains($m->getSubject(), (string) $goneId)
        ));
    }

    public function test_dry_run_sends_nothing_but_still_counts_groups(): void
    {
        [, $recipient] = $this->pendingMessage('-3 days');
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);

        $result = $this->service->processReview(true);

        $this->assertGreaterThanOrEqual(1, $result['notified_groups']);
        $this->assertGreaterThanOrEqual(1, $result['total_pending']);
        $this->assertSame([], $this->sent());
    }

    public function test_mentors_receive_a_summary_listing_the_group_and_its_count(): void
    {
        $group = $this->createTestGroup();
        for ($i = 0; $i < 2; $i++) {
            [, $recipient] = $this->pendingMessage('-3 days');
            $this->createMembership($recipient, $group);
        }

        $this->service->processReview();

        $summaries = array_values(array_filter(
            $this->sent(),
            fn ($m) => $m->getSubject() === 'Summary of chat messages waiting for review'
        ));
        $this->assertCount(1, $summaries);
        $this->assertSame('mentors-test@example.org', $summaries[0]->getTo()[0]->getAddress());
        $this->assertSame('support-test@example.org', $summaries[0]->getFrom()[0]->getAddress());
        $this->assertStringContainsString($group->nameshort . " count 2\r\n", $summaries[0]->getTextBody());
        $this->assertStringContainsString('more than 48 hours', $summaries[0]->getTextBody());
    }

    public function test_no_summary_when_nothing_is_notified(): void
    {
        // A pending message whose only group is ineligible. Other leaked rows
        // may still produce a summary, so only assert on our own group.
        [, $recipient] = $this->pendingMessage('-3 days');
        $group = $this->createTestGroup(['publish' => 0]);
        $this->createMembership($recipient, $group);

        $this->service->processReview();

        foreach ($this->sent() as $m) {
            if ($m->getSubject() === 'Summary of chat messages waiting for review') {
                $this->assertStringNotContainsString($group->nameshort, $m->getTextBody());
            }
        }
        $this->assertSame([], $this->sentFor($group));
    }

    public function test_stale_message_is_rejected_before_notify_so_group_not_mailed(): void
    {
        // Over 7 days old: the reject pass runs first and takes it out of the
        // pending set, so the group hears nothing about it.
        $this->createModbot();
        [, $recipient] = $this->pendingMessage('-8 days');
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);

        $this->service->processReview();

        $this->assertSame([], $this->sentFor($group));
    }

    public function test_stale_message_is_still_notified_when_there_is_no_modbot(): void
    {
        [, $recipient] = $this->pendingMessage('-8 days');
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);

        $this->service->processReview();

        $this->assertCount(1, $this->sentFor($group));
    }

    public function test_result_has_expected_shape(): void
    {
        $result = $this->service->processReview(true);

        $this->assertSame(['rejected', 'notified_groups', 'total_pending'], array_keys($result));
        $this->assertContainsOnly('int', $result);
    }
}
