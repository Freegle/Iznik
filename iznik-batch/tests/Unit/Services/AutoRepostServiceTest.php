<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\MessageOutcome;
use App\Services\AutoRepostService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AutoRepostServiceTest extends TestCase
{
    protected AutoRepostService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure feature flag is enabled for tests.
        config(['freegle.mail.enabled_types' => config('freegle.mail.enabled_types') . ',AutoRepost']);
        $this->service = new AutoRepostService();
    }

    /**
     * Create a repost-eligible message: our domain, source=Platform, approved, old enough.
     */
    private function createRepostCandidate(
        ?object $user = null,
        ?object $group = null,
        int $hoursOld = 80,
        int $autoreposts = 0,
        string $type = 'Offer',
    ): array {
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        $user = $user ?? $this->createTestUser();
        $group = $group ?? $this->createTestGroup();

        // User must have been active recently.
        DB::table('users')->where('id', $user->id)->update([
            'lastaccess' => now()->subHours(1),
        ]);

        $this->createMembership($user, $group, [
            'added' => now()->subDays(30),
        ]);

        $message = $this->createTestMessage($user, $group, [
            'type' => $type,
            'fromaddr' => 'test-' . $user->id . '@' . $domain,
            'source' => Message::SOURCE_PLATFORM,
        ]);

        // Set arrival to make message old enough for repost.
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'arrival' => now()->subHours($hoursOld),
                'autoreposts' => $autoreposts,
            ]);

        return ['user' => $user, 'group' => $group, 'message' => $message];
    }

    public function test_no_messages_returns_zero_stats(): void
    {
        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
        $this->assertEquals(0, $stats['warned']);
        $this->assertEquals(0, $stats['skipped']);
        $this->assertEquals(0, $stats['errors']);
    }

    public function test_reposts_offer_after_interval(): void
    {
        // Default offer interval is 3 days = 72 hours.
        // Message is 80 hours old, so past the 72h threshold.
        $data = $this->createRepostCandidate(hoursOld: 80);

        $stats = $this->service->process();

        $this->assertEquals(1, $stats['reposted']);

        // Verify autoreposts incremented.
        $mg = DB::table('messages_groups')
            ->where('msgid', $data['message']->id)
            ->where('groupid', $data['group']->id)
            ->first();
        $this->assertEquals(1, $mg->autoreposts);

        // Verify log entry.
        $this->assertDatabaseHas('logs', [
            'msgid' => $data['message']->id,
            'groupid' => $data['group']->id,
            'type' => 'Message',
            'subtype' => 'Autoreposted',
        ]);

        // Verify messages_postings entry.
        $this->assertDatabaseHas('messages_postings', [
            'msgid' => $data['message']->id,
            'groupid' => $data['group']->id,
            'repost' => 1,
            'autorepost' => 1,
        ]);
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $data = $this->createRepostCandidate(hoursOld: 80);

        $stats = $this->service->process(dryRun: true);

        $this->assertEquals(1, $stats['reposted']);

        // Autoreposts should still be 0.
        $mg = DB::table('messages_groups')
            ->where('msgid', $data['message']->id)
            ->where('groupid', $data['group']->id)
            ->first();
        $this->assertEquals(0, $mg->autoreposts);

        // No log entries.
        $this->assertDatabaseMissing('logs', [
            'msgid' => $data['message']->id,
            'subtype' => 'Autoreposted',
        ]);
    }

    public function test_skips_message_at_max_reposts(): void
    {
        // Default max is 5.
        $data = $this->createRepostCandidate(hoursOld: 80, autoreposts: 5);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_skips_message_with_outcome(): void
    {
        $data = $this->createRepostCandidate(hoursOld: 80);

        // Add an outcome (TAKEN).
        MessageOutcome::create([
            'msgid' => $data['message']->id,
            'outcome' => MessageOutcome::OUTCOME_TAKEN,
            'timestamp' => now(),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_skips_message_with_promise(): void
    {
        $data = $this->createRepostCandidate(hoursOld: 80);

        $replier = $this->createTestUser();
        DB::table('messages_promises')->insert([
            'msgid' => $data['message']->id,
            'userid' => $replier->id,
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_skips_non_platform_source(): void
    {
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('users')->where('id', $user->id)->update([
            'lastaccess' => now()->subHours(1),
        ]);

        $this->createMembership($user, $group, [
            'added' => now()->subDays(30),
        ]);

        $message = $this->createTestMessage($user, $group, [
            'fromaddr' => 'test@' . $domain,
            'source' => 'Email',
        ]);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(['arrival' => now()->subHours(80)]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_skips_non_our_domain(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        DB::table('users')->where('id', $user->id)->update([
            'lastaccess' => now()->subHours(1),
        ]);

        $this->createMembership($user, $group, [
            'added' => now()->subDays(30),
        ]);

        $message = $this->createTestMessage($user, $group, [
            'fromaddr' => 'test@external.com',
            'source' => Message::SOURCE_PLATFORM,
        ]);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update(['arrival' => now()->subHours(80)]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
        $this->assertGreaterThan(0, $stats['skipped']);
    }

    public function test_skips_closed_group(): void
    {
        $data = $this->createRepostCandidate(hoursOld: 80);

        // Close the group.
        DB::table('groups')->where('id', $data['group']->id)->update([
            'settings' => json_encode(['closed' => true]),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_skips_group_with_autofunctionoverride(): void
    {
        $data = $this->createRepostCandidate(hoursOld: 80);

        DB::table('groups')->where('id', $data['group']->id)->update([
            'autofunctionoverride' => 1,
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_skips_message_with_recent_chat_reply(): void
    {
        $data = $this->createRepostCandidate(hoursOld: 80);

        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($data['user'], $replier);

        // Add a chat message referencing this message, recently.
        $this->createTestChatMessage($room, $replier, [
            'refmsgid' => $data['message']->id,
            'date' => now()->subHours(1),
        ]);

        $stats = $this->service->process();

        // Should be skipped due to recent reply (within interval days).
        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_skips_user_with_autoreposts_disabled(): void
    {
        $data = $this->createRepostCandidate(hoursOld: 80);

        DB::table('users')->where('id', $data['user']->id)->update([
            'settings' => json_encode(['autorepostsdisable' => true]),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
        $this->assertGreaterThan(0, $stats['skipped']);
    }

    public function test_skips_inactive_user(): void
    {
        $data = $this->createRepostCandidate(hoursOld: 80);

        // User's last access is older than the message itself.
        DB::table('users')->where('id', $data['user']->id)->update([
            'lastaccess' => now()->subHours(200),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_skips_deleted_message(): void
    {
        $data = $this->createRepostCandidate(hoursOld: 80);

        DB::table('messages')->where('id', $data['message']->id)->update([
            'deleted' => now(),
        ]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_warns_in_window_before_repost(): void
    {
        // Offer interval is 3 days = 72 hours.
        // Warning window: between 48h (2*24) and 72h (3*24).
        $data = $this->createRepostCandidate(hoursOld: 50);

        $stats = $this->service->process();

        $this->assertEquals(1, $stats['warned']);
        $this->assertEquals(0, $stats['reposted']);

        // Verify lastautopostwarning was set.
        $mg = DB::table('messages_groups')
            ->where('msgid', $data['message']->id)
            ->where('groupid', $data['group']->id)
            ->first();
        $this->assertNotNull($mg->lastautopostwarning);
    }

    public function test_wanted_uses_longer_interval(): void
    {
        // Default wanted interval is 7 days = 168 hours.
        // At 80 hours, a wanted message should NOT be reposted.
        $data = $this->createRepostCandidate(hoursOld: 80, type: 'Wanted');

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_wanted_reposts_after_interval(): void
    {
        // Default wanted interval is 7 days = 168 hours.
        // At 170 hours, should be reposted.
        $data = $this->createRepostCandidate(hoursOld: 170, type: 'Wanted');

        $stats = $this->service->process();

        $this->assertEquals(1, $stats['reposted']);
    }

    public function test_skips_message_past_max_age(): void
    {
        // Max age = interval * (max + 1) = 3 * 6 = 18 days = 432 hours.
        $data = $this->createRepostCandidate(hoursOld: 500);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_constants(): void
    {
        $this->assertEquals(90, AutoRepostService::LOOKBACK_DAYS);
        $this->assertEquals([
            'offer' => 3,
            'wanted' => 7,
            'max' => 5,
            'chaseups' => 5,
        ], AutoRepostService::DEFAULT_REPOSTS);
    }

    public function test_reposts_eligible_message_in_90_day_window(): void
    {
        // Test that messages within the 90-day mindate window are reposted if eligible.
        // The 90-day window is a filter (messages must be newer than 90 days ago),
        // but there's also a maxAge check: messages older than interval * (max + 1) days
        // are excluded. For offers (interval=3, max=5), maxAge = 18 days.
        // This test uses a message within the reposting window.

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');

        DB::table('users')->where('id', $user->id)->update([
            'lastaccess' => now()->subHours(1),
        ]);

        $this->createMembership($user, $group, [
            'added' => now()->subDays(30),
        ]);

        // Create a message that's 10 days old (within maxAge window of 18 days, past 72h offer interval)
        $message = $this->createTestMessage($user, $group, [
            'type' => 'Offer',
            'fromaddr' => 'test-' . $user->id . '@' . $domain,
            'source' => Message::SOURCE_PLATFORM,
        ]);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'arrival' => now()->subDays(10),
                'autoreposts' => 0,
            ]);

        // This should be eligible: 10 days is within maxAge window, past 72h offer interval
        $stats = $this->service->process();

        $this->assertEquals(1, $stats['reposted']);
    }

    public function test_recent_reply_detection_boundary(): void
    {
        // Test that recent replies within the interval days are correctly detected
        // and prevent reposting. This tests the fix for the V1 bug where recent
        // replies were only checked within 3 hours instead of 3 days.

        $data = $this->createRepostCandidate(hoursOld: 80);

        $replier = $this->createTestUser();
        $room = $this->createTestChatRoom($data['user'], $replier);

        // Add a chat message with a reply within the last 2 days (but more than 3 hours)
        // This should be detected as a recent reply and message should be skipped.
        $this->createTestChatMessage($room, $replier, [
            'refmsgid' => $data['message']->id,
            'date' => now()->subHours(48), // 48 hours ago = 2 days
        ]);

        $stats = $this->service->process();

        // Should be skipped due to recent reply (within interval days)
        $this->assertEquals(0, $stats['reposted']);
        $this->assertGreaterThan(0, $stats['skipped']);
    }

    public function test_message_exactly_90_days_old_is_excluded(): void
    {
        // Test the boundary condition: messages exactly 90 days old should be EXCLUDED
        // because the query uses arrival > mindate (not >=).
        // This might be the bug if the intent was to include messages from the "last 90 days".

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');

        DB::table('users')->where('id', $user->id)->update([
            'lastaccess' => now()->subHours(1),
        ]);

        $this->createMembership($user, $group, [
            'added' => now()->subDays(100),
        ]);

        // Create a message that's exactly 90 days old
        $message = $this->createTestMessage($user, $group, [
            'type' => 'Offer',
            'fromaddr' => 'test-' . $user->id . '@' . $domain,
            'source' => Message::SOURCE_PLATFORM,
        ]);

        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group->id)
            ->update([
                'arrival' => now()->subDays(90),
                'autoreposts' => 0,
            ]);

        // This message should be EXCLUDED by the mindate > filter
        // because it's exactly 90 days old.
        // If it should be included, this test will fail and reveal the bug.
        $stats = $this->service->process();

        // Based on current code, this should NOT be reposted (should be skipped by getCandidates)
        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_skips_message_if_user_left_group(): void
    {
        // Test that messages are not reposted if the user is no longer a member of the group.
        // This tests the INNER JOIN on memberships - if membership is deleted, message is excluded.

        $data = $this->createRepostCandidate(hoursOld: 80);

        // Remove the user's membership from the group
        DB::table('memberships')
            ->where('userid', $data['user']->id)
            ->where('groupid', $data['group']->id)
            ->delete();

        $stats = $this->service->process();

        // Should not be reposted because user is no longer a member
        $this->assertEquals(0, $stats['reposted']);
    }

    public function test_multiple_groups_each_repost_independently(): void
    {
        // Test that when a message is posted to multiple groups,
        // it's reposted on EACH group independently (multi-group fix).

        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        $domain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');

        DB::table('users')->where('id', $user->id)->update([
            'lastaccess' => now()->subHours(1),
        ]);

        $this->createMembership($user, $group1, ['added' => now()->subDays(30)]);
        $this->createMembership($user, $group2, ['added' => now()->subDays(30)]);

        // Create message and post to both groups
        $message = $this->createTestMessage($user, $group1, [
            'type' => 'Offer',
            'fromaddr' => 'test-' . $user->id . '@' . $domain,
            'source' => Message::SOURCE_PLATFORM,
        ]);

        // Add to group2 as well
        DB::table('messages_groups')->insert([
            'msgid' => $message->id,
            'groupid' => $group2->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subHours(80),
            'autoreposts' => 0,
        ]);

        // Set group1's entry arrival time for repost
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group1->id)
            ->update(['arrival' => now()->subHours(80)]);

        $stats = $this->service->process();

        // Should repost on BOTH groups (2 total)
        $this->assertEquals(2, $stats['reposted']);

        // Verify autoreposts incremented on both group entries
        $mg1 = DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group1->id)
            ->first();
        $mg2 = DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->where('groupid', $group2->id)
            ->first();

        $this->assertEquals(1, $mg1->autoreposts);
        $this->assertEquals(1, $mg2->autoreposts);
    }

    /**
     * Regression test for Discourse #9481 (posts 502-510): messages 119974515 and 116335125
     * were not auto-reposted even though they were eligible.
     *
     * Root cause: AutoRepostService::process() returned early when 'AutoRepost' was absent
     * from FREEGLE_MAIL_ENABLED_TYPES, halting ALL reposts — not just warning emails.
     * The feature flag controls email sending; it must not gate the actual DB repost.
     */
    public function test_reposts_still_happen_when_warning_emails_disabled(): void
    {
        // Simulate 'AutoRepost' removed from FREEGLE_MAIL_ENABLED_TYPES (e.g. to disable
        // warning emails) — reposts must still proceed.
        config(['freegle.mail.enabled_types' => '']);

        $data = $this->createRepostCandidate(hoursOld: 80);

        $stats = $this->service->process();

        // Repost must happen even with warning emails disabled.
        $this->assertEquals(1, $stats['reposted'], 'Repost should happen even when AutoRepost emails are disabled');

        // Verify the DB was actually updated.
        $mg = DB::table('messages_groups')
            ->where('msgid', $data['message']->id)
            ->where('groupid', $data['group']->id)
            ->first();
        $this->assertEquals(1, $mg->autoreposts, 'autoreposts counter must be incremented');

        // Verify messages_postings entry was created.
        $this->assertDatabaseHas('messages_postings', [
            'msgid' => $data['message']->id,
            'groupid' => $data['group']->id,
            'repost' => 1,
            'autorepost' => 1,
        ]);
    }

    /**
     * Regression: Discourse #9481 post 502 — Derek reported items 119974515 and 116335125
     * were not auto-reposted despite being "bumped 4 days ago" (i.e. 96h old, past the
     * 3-day offer interval).  Root cause: the early return in process() when 'AutoRepost'
     * is absent from FREEGLE_MAIL_ENABLED_TYPES stopped all reposts, not just emails.
     *
     * A message at 96 h (day 4) with a 3-day offer interval satisfies hoursago > interval * 24
     * and must be caught up as soon as the system runs again, regardless of email state.
     *
     * Without fix: process() returns early → 0 reposts (message stays stuck indefinitely).
     * With fix:    process() runs, hits the repost branch, increments autoreposts → 1 repost.
     */
    public function test_missed_repost_window_caught_up_when_email_disabled(): void
    {
        // Simulate the condition that caused Derek's items to be missed:
        // 'AutoRepost' removed from FREEGLE_MAIL_ENABLED_TYPES (e.g. to suppress warning emails).
        config(['freegle.mail.enabled_types' => '']);

        // 96 h = 4 days: past the 3-day offer interval and past the 24 h warning window.
        // This matches "should have been bumped 4 days ago" from Discourse #9481 post 502.
        $data = $this->createRepostCandidate(hoursOld: 96);

        $stats = $this->service->process();

        $this->assertEquals(
            1,
            $stats['reposted'],
            'Item that missed its repost window while email was disabled must be caught up on next run'
        );

        // DB must reflect the repost.
        $mg = DB::table('messages_groups')
            ->where('msgid', $data['message']->id)
            ->where('groupid', $data['group']->id)
            ->first();
        $this->assertEquals(1, $mg->autoreposts, 'autoreposts counter must be incremented after catch-up');
    }

    public function test_warning_email_skipped_when_email_type_disabled(): void
    {
        // When 'AutoRepost' is removed from FREEGLE_MAIL_ENABLED_TYPES, warning emails
        // must not be sent, but the message must still be counted as warned (so callers
        // can see the service ran).
        config(['freegle.mail.enabled_types' => '']);

        // Message in the warning window: offer interval=3d=72h; use hoursOld=50 (within 48-72h).
        $data = $this->createRepostCandidate(hoursOld: 50);

        $stats = $this->service->process();

        // No repost at 50h (not yet past interval).
        $this->assertEquals(0, $stats['reposted']);

        // The warned count reflects the service ran, but no email was sent.
        $this->assertEquals(1, $stats['warned']);

        // lastautopostwarning must NOT have been updated (no email sent = no warning logged).
        $mg = DB::table('messages_groups')
            ->where('msgid', $data['message']->id)
            ->where('groupid', $data['group']->id)
            ->first();
        $this->assertNull($mg->lastautopostwarning, 'lastautopostwarning should not be set when emails are disabled');
    }
}
