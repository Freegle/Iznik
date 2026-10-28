<?php

namespace Tests\Feature\Chat;

use App\Models\Group;
use App\Services\ChatReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature-level coverage of {@see ChatReviewService} across several groups at once.
 *
 * The per-branch cases live in Tests\Unit\Services\ChatReviewServiceTest. These
 * cover the combinations: dry run versus live, mixed eligible and ineligible
 * groups, and the mentors summary. Mail::raw() sends nothing under Mail::fake(),
 * so mail is read from the "array" mailer, and counts are scoped to fixtures
 * built here because the test database is not empty mid-suite.
 */
class ChatReviewServiceTest extends TestCase
{
    private ChatReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatReviewService();

        config(['freegle.mail.moderator_email' => $this->uniqueEmail('modbot')]);
        config(['freegle.mail.support_addr' => 'support-test@example.org']);
        config(['freegle.mail.mentors_addr' => 'mentors-test@example.org']);
        config(['freegle.mail.group_domain' => 'groups.example.org']);

        Mail::mailer('array')->getSymfonyTransport()->flush();
    }

    private function sent(): array
    {
        return array_map(
            fn ($m) => $m->getOriginalMessage(),
            Mail::mailer('array')->getSymfonyTransport()->messages()->all()
        );
    }

    private function sentFor(Group $group): array
    {
        return array_values(array_filter(
            $this->sent(),
            fn ($m) => str_contains($m->getSubject(), $group->nameshort)
        ));
    }

    private function summaries(): array
    {
        return array_values(array_filter(
            $this->sent(),
            fn ($m) => $m->getSubject() === 'Summary of chat messages waiting for review'
        ));
    }

    /** Pending chat messages for a group's members; returns the message ids. */
    private function pendingFor(Group $group, int $n, string $age = '-3 days'): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $sender = $this->createTestUser();
            $recipient = $this->createTestUser();
            $this->createMembership($recipient, $group);
            $room = $this->createTestChatRoom($sender, $recipient);
            $ids[] = $this->createTestChatMessage($room, $sender, [
                'date' => date('Y-m-d H:i:s', strtotime($age)),
                'reviewrequired' => 1,
                'reviewedby' => null,
                'reviewrejected' => 0,
            ])->id;
        }

        return $ids;
    }

    public static function dryRunProvider(): array
    {
        return [
            'live run sends group and mentors mail' => [false, 2],
            'dry run sends nothing' => [true, 0],
        ];
    }

    #[DataProvider('dryRunProvider')]
    public function test_two_groups_notified_and_summarised_unless_dry_run(bool $dryRun, int $expectedGroupMails): void
    {
        $one = $this->createTestGroup(['contactmail' => 'one-' . uniqid() . '@example.org']);
        $many = $this->createTestGroup(['contactmail' => 'many-' . uniqid() . '@example.org']);
        $this->pendingFor($one, 1);
        $this->pendingFor($many, 3);

        $result = $this->service->processReview($dryRun);

        $this->assertGreaterThanOrEqual(2, $result['notified_groups']);
        $this->assertGreaterThanOrEqual(4, $result['total_pending']);
        $this->assertCount($dryRun ? 0 : 1, $this->sentFor($one));
        $this->assertCount($dryRun ? 0 : 1, $this->sentFor($many));
        $this->assertCount($expectedGroupMails ? 1 : 0, array_filter(
            $this->summaries(),
            fn ($m) => str_contains($m->getTextBody(), $one->nameshort . " count 1\r\n")
                && str_contains($m->getTextBody(), $many->nameshort . " count 3\r\n")
        ));
        if (!$dryRun) {
            $this->assertStringStartsWith('1 message between', $this->sentFor($one)[0]->getSubject());
            $this->assertStringStartsWith('3 messages between', $this->sentFor($many)[0]->getSubject());
        }
    }

    public function test_ineligible_group_is_left_out_of_counts_and_summary(): void
    {
        $eligible = $this->createTestGroup();
        $unpublished = $this->createTestGroup(['publish' => 0]);
        $other = $this->createTestGroup(['type' => Group::TYPE_OTHER]);
        $this->pendingFor($eligible, 2);
        $this->pendingFor($unpublished, 2);
        $this->pendingFor($other, 2);

        $this->service->processReview();

        $this->assertCount(1, $this->sentFor($eligible));
        $this->assertSame([], $this->sentFor($unpublished));
        $this->assertSame([], $this->sentFor($other));
        foreach ($this->summaries() as $summary) {
            $this->assertStringNotContainsString($unpublished->nameshort, $summary->getTextBody());
            $this->assertStringNotContainsString($other->nameshort, $summary->getTextBody());
        }
    }

    public function test_return_counts_increase_by_exactly_the_fixtures_added(): void
    {
        $before = $this->service->processReview(true);

        $group = $this->createTestGroup();
        $this->pendingFor($group, 2);

        $after = $this->service->processReview(true);

        $this->assertSame($before['notified_groups'] + 1, $after['notified_groups']);
        $this->assertSame($before['total_pending'] + 2, $after['total_pending']);
        $this->assertSame($before['rejected'], $after['rejected']);
    }

    public function test_dry_run_rejected_count_matches_live_run_and_only_live_updates(): void
    {
        $modbot = $this->createTestUser();
        DB::table('users_emails')->where('userid', $modbot->id)
            ->update(['email' => config('freegle.mail.moderator_email')]);
        $group = $this->createTestGroup();
        $ids = $this->pendingFor($group, 2, '-9 days');

        $dry = $this->service->processReview(true);
        $this->assertSame(0, DB::table('chat_messages')->whereIn('id', $ids)->where('reviewrejected', 1)->count());

        $live = $this->service->processReview();
        $this->assertSame($dry['rejected'], $live['rejected']);
        $this->assertSame(2, DB::table('chat_messages')->whereIn('id', $ids)
            ->where('reviewrejected', 1)->where('reviewedby', $modbot->id)->count());

        // Rejected messages drop out of the pending set, so the group hears nothing.
        $this->assertSame([], $this->sentFor($group));
    }
}
