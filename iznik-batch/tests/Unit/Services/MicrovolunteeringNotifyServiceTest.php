<?php

namespace Tests\Unit\Services;

use App\Services\MicrovolunteeringNotifyService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for MicrovolunteeringNotifyService, exercised directly rather
 * than through the `microvolunteering:notify` artisan command.
 *
 * Tests\Feature\AI\MicrovolunteeringNotifyCommandTest already covers the
 * command's happy-path DB effects (who gets notified for Pending vs Approved
 * messages, the microactions review exclusion, etc). This class targets what
 * that Feature test does not:
 *   - the returned $stats array itself (the command never inspects it)
 *   - the CANDIDATES_PER_MESSAGE (10) cap via array_rand
 *   - the alreadyNotifiedToday cross-message exclusion (distinct from the
 *     per-message MAX_PER_USER existing-count check)
 *   - the dry-run branch of markStaleReviewedNotificationsSeen (SELECT COUNT
 *     rather than UPDATE)
 *   - the users_skipped++ stat for the existing-count-at-cap path specifically
 */
class MicrovolunteeringNotifyServiceTest extends TestCase
{
    private function createGroup(bool $microvolunteering = true): int
    {
        return DB::table('groups')->insertGetId([
            'nameshort'         => 'TestGroup' . uniqid(),
            'namefull'          => 'Test Group',
            'type'              => 'Freegle',
            'publish'           => 1,
            'onhere'            => 1,
            'microvolunteering' => $microvolunteering ? 1 : 0,
            'polyindex'         => DB::raw("ST_GeomFromText('POINT(-0.1 51.5)', 3857)"),
        ]);
    }

    private function createUser(string $trustlevel = 'Basic'): int
    {
        $userId = DB::table('users')->insertGetId([
            'fullname'   => 'Test User ' . uniqid(),
            'trustlevel' => $trustlevel,
            'lastaccess' => now(),
            'added'      => now(),
        ]);

        $email = 'test-' . uniqid() . '@example.com';
        DB::table('users_emails')->insert([
            'userid'    => $userId,
            'email'     => $email,
            'backwards' => strrev($email),
            'preferred' => 1,
            'added'     => now(),
        ]);

        return $userId;
    }

    private function addMembership(int $userId, int $groupId, string $role = 'Member'): void
    {
        DB::table('memberships')->insert([
            'userid'     => $userId,
            'groupid'    => $groupId,
            'role'       => $role,
            'collection' => 'Approved',
            'added'      => now(),
        ]);
    }

    private function createMessage(int $groupId, int $fromuser, string $collection = 'Approved'): int
    {
        $msgId = DB::table('messages')->insertGetId([
            'subject'  => 'OFFER: Test item (Test Area)',
            'message'  => 'Test item description.',
            'type'     => 'Offer',
            'fromuser' => $fromuser,
            'deleted'  => null,
            'heldby'   => null,
        ]);

        DB::table('messages_groups')->insert([
            'msgid'      => $msgId,
            'groupid'    => $groupId,
            'collection' => $collection,
            'arrival'    => now(),
        ]);

        return $msgId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Returned stats array
    // ─────────────────────────────────────────────────────────────────────────

    public function test_stats_are_all_zero_when_no_messages(): void
    {
        $service = new MicrovolunteeringNotifyService();

        $stats = $service->notifyForMessages();

        $this->assertSame([
            'messages_considered'         => 0,
            'users_notified'              => 0,
            'users_skipped'               => 0,
            'stale_notifications_cleared' => 0,
        ], $stats);
    }

    public function test_stats_reflect_a_single_notification(): void
    {
        $groupId  = $this->createGroup();
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        $this->createMessage($groupId, $fromUser, 'Pending');

        $service = new MicrovolunteeringNotifyService();
        $stats   = $service->notifyForMessages();

        $this->assertSame(1, $stats['messages_considered']);
        $this->assertSame(1, $stats['users_notified']);
        $this->assertSame(0, $stats['users_skipped']);
        $this->assertSame(0, $stats['stale_notifications_cleared']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CANDIDATES_PER_MESSAGE cap (array_rand branch)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_candidates_are_capped_at_ten_per_message(): void
    {
        $groupId  = $this->createGroup();
        $fromUser = $this->createUser('Basic');
        $this->addMembership($fromUser, $groupId);

        for ($i = 0; $i < 15; $i++) {
            $reviewer = $this->createUser('Moderate');
            $this->addMembership($reviewer, $groupId);
        }

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        $service = new MicrovolunteeringNotifyService();
        $stats   = $service->notifyForMessages();

        $this->assertSame(10, $stats['users_notified']);

        $inserted = DB::table('users_notifications')
            ->where('type', 'Exhort')
            ->where('url', '/microvolunteering/message/' . $msgId)
            ->count();
        $this->assertSame(10, $inserted);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // alreadyNotifiedToday — cross-message exclusion
    // ─────────────────────────────────────────────────────────────────────────

    public function test_user_notified_about_another_message_today_is_excluded_from_new_message(): void
    {
        $groupId  = $this->createGroup();
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        // Reviewer already got an Exhort notification today about a different message.
        DB::table('users_notifications')->insert([
            'touser'    => $reviewer,
            'type'      => 'Exhort',
            'url'       => '/microvolunteering/message/999999',
            'timestamp' => now(),
        ]);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        $service = new MicrovolunteeringNotifyService();
        $stats   = $service->notifyForMessages();

        $this->assertSame(1, $stats['messages_considered']);
        $this->assertSame(0, $stats['users_notified']);
        $this->assertSame(0, $stats['users_skipped']);

        $this->assertDatabaseMissing('users_notifications', [
            'touser' => $reviewer,
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // users_skipped++ for the per-message MAX_PER_USER existing-count check
    // (distinct from the alreadyNotifiedToday pool-filter path: the existing
    // rows here are deliberately outside the 24h window so the reviewer stays
    // eligible in the pool and is only rejected by the per-candidate count).
    // ─────────────────────────────────────────────────────────────────────────

    public function test_users_skipped_increments_when_existing_notifications_for_this_message_hit_cap(): void
    {
        $groupId  = $this->createGroup();
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');
        $url   = '/microvolunteering/message/' . $msgId;

        for ($i = 0; $i < 3; $i++) {
            DB::table('users_notifications')->insert([
                'touser'    => $reviewer,
                'type'      => 'Exhort',
                'url'       => $url,
                'timestamp' => now()->subDays(2),
            ]);
        }

        $service = new MicrovolunteeringNotifyService();
        $stats   = $service->notifyForMessages();

        $this->assertSame(0, $stats['users_notified']);
        $this->assertSame(1, $stats['users_skipped']);

        $this->assertSame(3, DB::table('users_notifications')
            ->where('touser', $reviewer)->where('url', $url)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dry-run branch of markStaleReviewedNotificationsSeen (SELECT COUNT, no UPDATE)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_dry_run_counts_stale_notifications_without_clearing_them(): void
    {
        $groupId  = $this->createGroup();
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        DB::table('microactions')->insert([
            'actiontype'     => 'CheckMessage',
            'userid'         => $reviewer,
            'msgid'          => $msgId,
            'result'         => 'Approve',
            'score_negative' => 0,
        ]);

        $notifId = DB::table('users_notifications')->insertGetId([
            'touser' => $reviewer,
            'type'   => 'Exhort',
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);

        $service = new MicrovolunteeringNotifyService();
        $stats   = $service->notifyForMessages(dryRun: true);

        $this->assertSame(1, $stats['stale_notifications_cleared']);

        $this->assertDatabaseHas('users_notifications', [
            'id'   => $notifId,
            'seen' => 0,
        ]);
    }

    public function test_non_dry_run_actually_clears_stale_notifications_and_matches_stat(): void
    {
        $groupId  = $this->createGroup();
        $fromUser = $this->createUser('Basic');
        $reviewer = $this->createUser('Moderate');

        $this->addMembership($fromUser, $groupId);
        $this->addMembership($reviewer, $groupId);

        $msgId = $this->createMessage($groupId, $fromUser, 'Pending');

        DB::table('microactions')->insert([
            'actiontype'     => 'CheckMessage',
            'userid'         => $reviewer,
            'msgid'          => $msgId,
            'result'         => 'Approve',
            'score_negative' => 0,
        ]);

        $notifId = DB::table('users_notifications')->insertGetId([
            'touser' => $reviewer,
            'type'   => 'Exhort',
            'url'    => '/microvolunteering/message/' . $msgId,
        ]);

        $service = new MicrovolunteeringNotifyService();
        $stats   = $service->notifyForMessages();

        $this->assertSame(1, $stats['stale_notifications_cleared']);

        $this->assertDatabaseHas('users_notifications', [
            'id'   => $notifId,
            'seen' => 1,
        ]);
    }
}
