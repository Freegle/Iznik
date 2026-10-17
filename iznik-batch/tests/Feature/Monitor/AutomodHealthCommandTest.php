<?php

namespace Tests\Feature\Monitor;

use App\Console\Commands\Monitor\AutomodHealthCommand;
use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * monitor:automod-health: fails (and so escalates) when automated review has been failing for a
 * post for a quarter of an hour or three tries, or when a post on an approve-mode community has
 * waited longer than the publish delay without any decision at all. A post that legitimately has
 * no current decision (held, deleted, flagged as spam, pulled back, rippled in, a moderated
 * member's) is never counted: the review would not run for it, so it could never clear.
 */
class AutomodHealthCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'freegle.autoapprove.enabled' => true,
            'freegle.autoapprove.trial_group_ids' => '',
            'freegle.automod.shadow_group_ids' => '',
            'freegle.autoapprove.delay_minutes' => 20,
        ]);
    }

    /**
     * A Pending post from an auto-moderated member whose content check ran at $checkedAt.
     *
     * @return array{0: int, 1: int, 2: int} msgid, groupid, userid
     */
    private function waitingPost(Carbon $checkedAt, array $mg = []): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)->update(array_merge([
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => $checkedAt->copy()->subMinute(),
            'contentcheck_checked_at' => $checkedAt,
        ], $mg));

        return [(int) $message->id, (int) $group->id, (int) $user->id];
    }

    private function unavailableRow(int $msgid, int $groupid, int $attempts = 3, ?Carbon $firstFailedAt = null): void
    {
        DB::table('messages_automod')->insert([
            'msgid' => $msgid, 'groupid' => $groupid, 'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => '', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end_node' => 'UNAVAILABLE',
            'reason' => 'Automated review unavailable', 'path' => '[]', 'created' => now()->subMinute(),
            'detail' => json_encode([
                'error' => 'HTTP 500',
                'attempts' => $attempts,
                'first_failed_at' => ($firstFailedAt ?? now()->subMinutes(16))->toDateTimeString(),
            ]),
        ]);
    }

    private function holdRow(int $msgid, int $groupid, string $end = 'HOLD_MONEY', string $mode = MessageAutomod::MODE_APPROVE): void
    {
        DB::table('messages_automod')->insert([
            'msgid' => $msgid, 'groupid' => $groupid, 'mode' => $mode,
            'chart_version' => '5', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end_node' => $end,
            'reason' => 'Asks for money', 'path' => '[]', 'created' => now()->subHours(49),
        ]);
    }

    /** The command, with Sentry replaced by a recorder. */
    private function commandSpy(): object
    {
        $spy = new class extends AutomodHealthCommand {
            /** @var array<int, array{message: string, fingerprint: array, extra: array}> */
            public array $escalations = [];

            protected function escalate(string $message, array $fingerprint, array $extra): void
            {
                $this->escalations[] = ['message' => $message, 'fingerprint' => $fingerprint, 'extra' => $extra];
            }
        };
        Artisan::registerCommand($spy);

        return $spy;
    }

    public function test_ok_when_nothing_is_waiting_and_nothing_failed(): void
    {
        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }

    // --- UNAVAILABLE: the review cannot decide a post --------------------------------------

    public function test_fails_when_a_review_has_been_failing_for_fifteen_minutes(): void
    {
        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(20));
        $this->unavailableRow($msgid, $groupid, 2, now()->subMinutes(16));

        $this->artisan('monitor:automod-health')
            ->expectsOutputToContain('UNAVAILABLE')
            ->assertExitCode(1);
    }

    public function test_fails_after_three_tries_even_inside_fifteen_minutes(): void
    {
        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(8));
        $this->unavailableRow($msgid, $groupid, 3, now()->subMinutes(7));

        $this->artisan('monitor:automod-health')
            ->expectsOutputToContain('UNAVAILABLE')
            ->assertExitCode(1);
    }

    /**
     * The age that counts is the failure's, not the content check's: a post that has been waiting
     * a while behind a backlog and fails once, a minute ago, is a blip, not an outage.
     */
    public function test_one_transient_failure_on_an_old_backlog_post_is_not_an_alert(): void
    {
        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(30));
        $this->unavailableRow($msgid, $groupid, 1, now()->subMinute());

        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }

    public function test_a_review_that_only_just_failed_is_not_an_alert(): void
    {
        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(5));
        $this->unavailableRow($msgid, $groupid, 1, now()->subMinute());

        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }

    public function test_a_row_from_before_attempts_were_kept_is_aged_by_its_own_timestamp(): void
    {
        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(40));
        DB::table('messages_automod')->insert([
            'msgid' => $msgid, 'groupid' => $groupid, 'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => '', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end_node' => 'UNAVAILABLE',
            'reason' => 'Automated review unavailable', 'path' => '[]', 'created' => now()->subMinute(),
            'detail' => json_encode(['error' => 'HTTP 500']),
        ]);

        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }

    // A post whose review will never be retried cannot clear, so it is not counted.

    public function test_unavailable_posts_that_will_never_be_retried_are_not_an_alert(): void
    {
        $arrangements = [
            'held on the community' => fn (int $m, int $g) => DB::table('messages_groups')->where('msgid', $m)->where('groupid', $g)->update(['heldby' => $this->createTestUser()->id]),
            'held on the message' => fn (int $m, int $g) => DB::table('messages')->where('id', $m)->update(['heldby' => $this->createTestUser()->id]),
            'spam reason on the community' => fn (int $m, int $g) => DB::table('messages_groups')->where('msgid', $m)->where('groupid', $g)->update(['spamreason' => 'reported']),
            'spam reason on the message' => fn (int $m, int $g) => DB::table('messages')->where('id', $m)->update(['spamreason' => 'reported']),
            'deleted by the member' => fn (int $m, int $g) => DB::table('messages')->where('id', $m)->update(['deleted' => now()]),
            'copy deleted' => fn (int $m, int $g) => DB::table('messages_groups')->where('msgid', $m)->where('groupid', $g)->update(['deleted' => 1]),
            'member deleted' => fn (int $m, int $g, int $u) => DB::table('users')->where('id', $u)->update(['deleted' => now()]),
            'rippled in' => fn (int $m, int $g) => DB::table('messages_groups')->where('msgid', $m)->where('groupid', $g)->update(['rippled_in' => 1]),
            'no longer Pending' => fn (int $m, int $g) => DB::table('messages_groups')->where('msgid', $m)->where('groupid', $g)->update(['collection' => MessageGroup::COLLECTION_APPROVED]),
        ];

        foreach ($arrangements as $label => $arrange) {
            DB::table('messages_automod')->delete();
            [$msgid, $groupid, $userid] = $this->waitingPost(now()->subMinutes(40));
            $this->unavailableRow($msgid, $groupid, 5, now()->subHour());
            $arrange($msgid, $groupid, $userid);

            $this->artisan('monitor:automod-health')->assertExitCode(0);
        }

        $this->assertTrue(true);
    }

    // --- UNDECIDED: an approve-mode post past the delay with no decision ----------------------

    public function test_fails_when_a_trial_post_is_older_than_the_delay_with_no_decision(): void
    {
        $this->waitingPost(now()->subMinutes(30));

        $this->artisan('monitor:automod-health')
            ->expectsOutputToContain('UNDECIDED')
            ->assertExitCode(1);
    }

    public function test_a_decided_post_is_not_an_alert(): void
    {
        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(30));
        DB::table('messages_automod')->insert([
            'msgid' => $msgid, 'groupid' => $groupid, 'mode' => MessageAutomod::MODE_APPROVE,
            'chart_version' => '5', 'verdict' => MessageAutomod::VERDICT_HOLD, 'end_node' => 'HOLD_MONEY',
            'reason' => 'Asks for money', 'path' => '[]', 'created' => now()->subMinutes(29),
        ]);

        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }

    /** A decision made in shadow mode does not count for a community that is now approve-only. */
    public function test_a_shadow_decision_does_not_count_as_a_decision_in_approve_mode(): void
    {
        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(30));
        DB::table('messages_automod')->insert([
            'msgid' => $msgid, 'groupid' => $groupid, 'mode' => MessageAutomod::MODE_SHADOW,
            'chart_version' => '5', 'verdict' => MessageAutomod::VERDICT_APPROVE, 'end_node' => 'APPROVE',
            'reason' => null, 'path' => '[]', 'created' => now()->subMinutes(29),
        ]);

        $this->artisan('monitor:automod-health')
            ->expectsOutputToContain('UNDECIDED')
            ->assertExitCode(1);
    }

    public function test_a_post_still_within_the_delay_is_not_an_alert(): void
    {
        $this->waitingPost(now()->subMinutes(10));

        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }

    public function test_undecided_posts_the_review_would_never_look_at_are_not_an_alert(): void
    {
        $arrangements = [
            'held on the community' => fn (int $m, int $g) => DB::table('messages_groups')->where('msgid', $m)->where('groupid', $g)->update(['heldby' => $this->createTestUser()->id]),
            'held on the message' => fn (int $m, int $g) => DB::table('messages')->where('id', $m)->update(['heldby' => $this->createTestUser()->id]),
            'spam reason on the community' => fn (int $m, int $g) => DB::table('messages_groups')->where('msgid', $m)->where('groupid', $g)->update(['spamreason' => 'reported']),
            'spam reason on the message' => fn (int $m, int $g) => DB::table('messages')->where('id', $m)->update(['spamreason' => 'reported']),
            'deleted by the member' => fn (int $m, int $g) => DB::table('messages')->where('id', $m)->update(['deleted' => now()]),
            'member deleted' => fn (int $m, int $g, int $u) => DB::table('users')->where('id', $u)->update(['deleted' => now()]),
            'pulled back by a moderator' => fn (int $m, int $g) => DB::table('messages_groups')->where('msgid', $m)->where('groupid', $g)->update(['needs_moderator' => 1]),
            'rippled in' => fn (int $m, int $g) => DB::table('messages_groups')->where('msgid', $m)->where('groupid', $g)->update(['rippled_in' => 1]),
            'moderated member' => fn (int $m, int $g, int $u) => DB::table('memberships')->where('userid', $u)->where('groupid', $g)->update(['ourPostingStatus' => 'MODERATED']),
            'edited and awaiting the content check' => fn (int $m, int $g) => DB::table('messages')->where('id', $m)->update(['editedat' => now()]),
        ];

        foreach ($arrangements as $label => $arrange) {
            DB::table('messages_automod')->delete();
            [$msgid, $groupid, $userid] = $this->waitingPost(now()->subMinutes(40));
            $arrange($msgid, $groupid, $userid);

            $this->artisan('monitor:automod-health')->assertExitCode(0);
        }

        $this->assertTrue(true);
    }

    public function test_quiet_when_automated_review_is_dark(): void
    {
        config(['freegle.autoapprove.enabled' => false]);
        $this->waitingPost(now()->subMinutes(30));

        $this->artisan('monitor:automod-health')->assertExitCode(0);
    }

    // --- escalation: one report per state, not one every fifteen minutes ---------------------

    public function test_it_escalates_once_per_state_and_again_after_an_hour(): void
    {
        $spy = $this->commandSpy();
        $this->waitingPost(now()->subMinutes(30));

        $this->artisan('monitor:automod-health')->assertExitCode(1);
        $this->assertCount(1, $spy->escalations);

        Carbon::setTestNow(now()->addMinutes(15));
        $this->artisan('monitor:automod-health')->assertExitCode(1);
        $this->assertCount(1, $spy->escalations, 'the same failure is not reported again a quarter of an hour later');

        Carbon::setTestNow(now()->addMinutes(50));
        $this->artisan('monitor:automod-health')->assertExitCode(1);
        $this->assertCount(2, $spy->escalations, 'but a failure that goes on is reported again hourly');
    }

    public function test_it_escalates_again_when_the_state_changes(): void
    {
        $spy = $this->commandSpy();
        $this->waitingPost(now()->subMinutes(30));
        $this->artisan('monitor:automod-health')->assertExitCode(1);
        $this->assertCount(1, $spy->escalations);

        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(25));
        $this->holdRow($msgid, $groupid, 'HOLD_MONEY');
        DB::table('messages_automod')->delete();
        [$msgid2, $groupid2] = $this->waitingPost(now()->subMinutes(30));
        $this->unavailableRow($msgid2, $groupid2);

        $this->artisan('monitor:automod-health')->assertExitCode(1);

        $this->assertCount(2, $spy->escalations, 'UNAVAILABLE joining UNDECIDED is a different state');
    }

    public function test_recovery_clears_the_state_so_the_next_failure_is_reported(): void
    {
        $spy = $this->commandSpy();
        [$msgid, $groupid] = $this->waitingPost(now()->subMinutes(30));
        $this->artisan('monitor:automod-health')->assertExitCode(1);
        $this->assertCount(1, $spy->escalations);

        $this->holdRow($msgid, $groupid);
        DB::table('messages_automod')->where('msgid', $msgid)->update(['created' => now()]);
        $this->artisan('monitor:automod-health')->assertExitCode(0);

        DB::table('messages_automod')->delete();
        $this->artisan('monitor:automod-health')->assertExitCode(1);

        $this->assertCount(2, $spy->escalations);
    }

    public function test_the_report_has_fixed_text_a_fingerprint_and_the_counts_in_extra(): void
    {
        $spy = $this->commandSpy();
        $this->waitingPost(now()->subMinutes(30));
        $this->waitingPost(now()->subMinutes(35));

        $this->artisan('monitor:automod-health')->assertExitCode(1);

        $report = $spy->escalations[0];
        $this->assertSame('[AutomodHealth] UNDECIDED', $report['message']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $report['message'], 'no counts in the text, so Sentry groups the runs together');
        $this->assertSame(['automod-health', 'UNDECIDED'], $report['fingerprint']);
        $this->assertSame(2, $report['extra']['undecided']);
        $this->assertGreaterThanOrEqual(34, $report['extra']['oldest_undecided_minutes']);
    }

    // --- M3: a hold with nobody to act on it is a warning -----------------------------------

    public function test_a_chart_held_post_nobody_has_touched_for_two_days_is_a_warning_not_a_failure(): void
    {
        [$msgid, $groupid] = $this->waitingPost(now()->subHours(50), ['arrival' => now()->subHours(50)]);
        $this->holdRow($msgid, $groupid);

        $this->artisan('monitor:automod-health')
            ->expectsOutputToContain('older than 48 hours')
            ->assertExitCode(0);
    }

    public function test_a_held_post_a_moderator_has_acted_on_is_not_a_warning(): void
    {
        [$msgid, $groupid, $userid] = $this->waitingPost(now()->subHours(50), ['arrival' => now()->subHours(50)]);
        $this->holdRow($msgid, $groupid);
        DB::table('logs')->insert([
            'timestamp' => now()->subHours(20), 'type' => 'Message', 'subtype' => 'Replied',
            'msgid' => $msgid, 'groupid' => $groupid, 'user' => $userid, 'byuser' => $this->createTestUser()->id,
        ]);

        $this->artisan('monitor:automod-health')->doesntExpectOutputToContain('older than 48 hours')->assertExitCode(0);
    }

    public function test_the_two_day_warning_is_only_for_holds_the_sweep_leaves_alone(): void
    {
        // The 48-hour sweep publishes a post the review could not decide, a community that
        // moderates everything, and every post on a shadow community; only a real hold on an
        // approve-mode community waits for ever.
        [$m1, $g1] = $this->waitingPost(now()->subHours(50), ['arrival' => now()->subHours(50)]);
        $this->holdRow($m1, $g1, 'UNAVAILABLE');
        [$m2, $g2] = $this->waitingPost(now()->subHours(50), ['arrival' => now()->subHours(50)]);
        $this->holdRow($m2, $g2, 'GROUP_MODERATES_ALL');
        [$m3, $g3] = $this->waitingPost(now()->subHours(50), ['arrival' => now()->subHours(50)]);
        $this->holdRow($m3, $g3, 'HOLD_MONEY', MessageAutomod::MODE_SHADOW);
        // The review that could not decide has only just failed, so it is not a failure yet either.
        DB::table('messages_automod')->where('msgid', $m1)->update([
            'detail' => json_encode(['attempts' => 1, 'first_failed_at' => now()->toDateTimeString()]),
        ]);
        config(['freegle.autoapprove.enabled' => false, 'freegle.autoapprove.trial_group_ids' => "{$g1},{$g2}", 'freegle.automod.shadow_group_ids' => (string) $g3]);

        $this->artisan('monitor:automod-health')
            ->doesntExpectOutputToContain('older than 48 hours')
            ->assertExitCode(0);
    }
}
