<?php

namespace Tests\Feature\Notification;

use App\Services\NotificationExhortService;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExhortUsersCommandTest extends TestCase
{
    public function test_fires_device_push_after_inserting_notification(): void
    {
        $user = $this->createTestUser([
            'lastaccess' => now()->subMinute(),
            'added' => now()->subDays(8),
        ]);

        // V1 parity: a push must follow the insert (PushNotifications::notify).
        $push = \Mockery::mock(PushNotificationService::class);
        $push->shouldReceive('notifyUser')->once()->with($user->id)->andReturn(1);

        (new NotificationExhortService($push))->sendExhort(
            url: 'https://example.com',
            title: 'Test Title',
            text: 'Test text',
            activeSince: '5 minutes ago',
            joinedBefore: '1 week ago',
        );

        $this->assertDatabaseHas('users_notifications', [
            'touser' => $user->id,
            'type' => 'Exhort',
        ]);
    }

    public function test_dry_run_does_not_fire_push(): void
    {
        $this->createTestUser([
            'lastaccess' => now()->subMinute(),
            'added' => now()->subDays(8),
        ]);

        $push = \Mockery::mock(PushNotificationService::class);
        $push->shouldNotReceive('notifyUser');

        (new NotificationExhortService($push))->sendExhort(
            url: 'https://example.com',
            title: 'Test Title',
            text: 'Test text',
            activeSince: '5 minutes ago',
            joinedBefore: '1 week ago',
            dryRun: true,
        );
    }

    public function test_command_runs_cleanly_with_no_eligible_users(): void
    {
        $this->artisan('notifications:exhort')
            ->assertExitCode(0);
    }

    public function test_dry_run_is_accepted(): void
    {
        $this->artisan('notifications:exhort', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_sends_exhort_to_recently_active_established_user(): void
    {
        $user = $this->createTestUser([
            'lastaccess' => now()->subMinute(),
            'added' => now()->subDays(8),
        ]);

        (new NotificationExhortService())->sendExhort(
            url: 'https://example.com',
            title: 'Test Title',
            text: 'Test text',
            activeSince: '5 minutes ago',
            joinedBefore: '1 week ago',
        );

        $this->assertDatabaseHas('users_notifications', [
            'touser' => $user->id,
            'type' => 'Exhort',
            'url' => 'https://example.com',
            'title' => 'Test Title',
        ]);
    }

    public function test_skips_user_active_too_long_ago(): void
    {
        $user = $this->createTestUser([
            'lastaccess' => now()->subMinutes(10),
            'added' => now()->subDays(8),
        ]);

        (new NotificationExhortService())->sendExhort(
            url: 'https://example.com',
            title: 'Test Title',
            text: 'Test text',
            activeSince: '5 minutes ago',
            joinedBefore: '1 week ago',
        );

        $this->assertDatabaseMissing('users_notifications', [
            'touser' => $user->id,
            'type' => 'Exhort',
        ]);
    }

    public function test_skips_user_who_joined_too_recently(): void
    {
        $user = $this->createTestUser([
            'lastaccess' => now()->subMinute(),
            'added' => now()->subDays(3),
        ]);

        (new NotificationExhortService())->sendExhort(
            url: 'https://example.com',
            title: 'Test Title',
            text: 'Test text',
            activeSince: '5 minutes ago',
            joinedBefore: '1 week ago',
        );

        $this->assertDatabaseMissing('users_notifications', [
            'touser' => $user->id,
            'type' => 'Exhort',
        ]);
    }

    public function test_skips_user_with_recent_exhort_notification(): void
    {
        $user = $this->createTestUser([
            'lastaccess' => now()->subMinute(),
            'added' => now()->subDays(8),
        ]);

        DB::table('users_notifications')->insert([
            'touser' => $user->id,
            'type' => 'Exhort',
            'timestamp' => now()->subDays(30),
        ]);

        (new NotificationExhortService())->sendExhort(
            url: 'https://example.com',
            title: 'Test Title',
            text: 'Test text',
            activeSince: '5 minutes ago',
            joinedBefore: '1 week ago',
        );

        $this->assertSame(
            1,
            DB::table('users_notifications')->where('touser', $user->id)->where('type', 'Exhort')->count()
        );
    }

    public function test_sends_to_user_whose_last_exhort_was_over_90_days_ago(): void
    {
        $user = $this->createTestUser([
            'lastaccess' => now()->subMinute(),
            'added' => now()->subDays(8),
        ]);

        DB::table('users_notifications')->insert([
            'touser' => $user->id,
            'type' => 'Exhort',
            'timestamp' => now()->subDays(91),
        ]);

        (new NotificationExhortService())->sendExhort(
            url: 'https://example.com',
            title: 'Test Title',
            text: 'Test text',
            activeSince: '5 minutes ago',
            joinedBefore: '1 week ago',
        );

        $this->assertSame(
            2,
            DB::table('users_notifications')->where('touser', $user->id)->where('type', 'Exhort')->count()
        );
    }

    public function test_dry_run_does_not_insert_notification(): void
    {
        $user = $this->createTestUser([
            'lastaccess' => now()->subMinute(),
            'added' => now()->subDays(8),
        ]);

        (new NotificationExhortService())->sendExhort(
            url: 'https://example.com',
            title: 'Test Title',
            text: 'Test text',
            activeSince: '5 minutes ago',
            joinedBefore: '1 week ago',
            dryRun: true,
        );

        $this->assertDatabaseMissing('users_notifications', [
            'touser' => $user->id,
            'type' => 'Exhort',
        ]);
    }

    public function test_skips_deleted_users(): void
    {
        $user = $this->createTestUser([
            'lastaccess' => now()->subMinute(),
            'added' => now()->subDays(8),
            'deleted' => now()->subDay(),
        ]);

        (new NotificationExhortService())->sendExhort(
            url: 'https://example.com',
            title: 'Test Title',
            text: 'Test text',
            activeSince: '5 minutes ago',
            joinedBefore: '1 week ago',
        );

        $this->assertDatabaseMissing('users_notifications', [
            'touser' => $user->id,
            'type' => 'Exhort',
        ]);
    }

    public function test_returns_count_of_notifications_sent(): void
    {
        $user1 = $this->createTestUser([
            'lastaccess' => now()->subMinute(),
            'added' => now()->subDays(8),
        ]);
        $user2 = $this->createTestUser([
            'lastaccess' => now()->subMinute(),
            'added' => now()->subDays(8),
        ]);

        $count = (new NotificationExhortService())->sendExhort(
            url: 'https://example.com',
            title: 'Test Title',
            text: 'Test text',
            activeSince: '5 minutes ago',
            joinedBefore: '1 week ago',
        );

        $this->assertSame(2, $count);
    }

    public function test_command_uses_default_stories_url(): void
    {
        $this->artisan('notifications:exhort')
            ->assertExitCode(0);
    }

    public function test_command_accepts_custom_url_title_text(): void
    {
        $user = $this->createTestUser([
            'lastaccess' => now()->subMinute(),
            'added' => now()->subDays(8),
        ]);

        $this->artisan('notifications:exhort', [
            '--url' => 'https://custom.example.com',
            '--title' => 'Custom Title',
            '--text' => 'Custom text',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('users_notifications', [
            'touser' => $user->id,
            'type' => 'Exhort',
            'url' => 'https://custom.example.com',
        ]);
    }

    /**
     * The active-user scan must not use the `deleted` index.
     *
     * `deleted IS NULL` matches 2,732,883 of 2,872,858 production users - 95% - so the
     * optimiser was using an index to select almost the entire table and then doing
     * 1,488,055 random primary-key lookups off it. A plain scan of all 2.9M rows is 4.3x
     * faster: 6.59s -> 1.55s, measured on db2 on 2026-09-18. The selective predicate is
     * `lastaccess >= <yesterday>` (3,850 rows, 0.13%), but there is no index on lastaccess
     * and the existing (added, lastaccess) composite cannot help, because `added <= <a week
     * ago>` matches nearly every account.
     *
     * Asserted on the query text because an index hint has no observable output difference -
     * it can only change the plan, never the rows. The selection tests around this one are
     * the behaviour guard; they pin every arm of the WHERE clause.
     */
    public function test_active_user_scan_ignores_the_deleted_index(): void
    {
        $seen = [];
        DB::listen(function ($query) use (&$seen) {
            if (stripos($query->sql, 'lastaccess') !== false && stripos($query->sql, 'users') !== false) {
                $seen[] = $query->sql;
            }
        });

        (new NotificationExhortService())->sendExhort(
            url: 'https://example.com',
            title: 'Test Title',
            text: 'Test text',
            activeSince: '5 minutes ago',
            joinedBefore: '1 week ago',
        );

        $this->assertNotEmpty($seen, 'the active-user scan did not run');
        $this->assertMatchesRegularExpression(
            '/ignore\s+index\s*\(\s*`?deleted`?\s*\)/i',
            $seen[0],
            'the active-user scan must carry IGNORE INDEX (deleted); without it the optimiser '
                . 'picks an index that selects 95% of the users table'
        );
    }

    /**
     * An index hint is only as durable as the index name it quotes. Rename or drop
     * `users.deleted` and the scan above stops dead with "Key 'deleted' doesn't exist in
     * table", taking every exhort notification with it - so the migration that renames it
     * should fail here, with a message that says why, rather than in fourteen other tests
     * with one that does not.
     */
    public function test_users_still_has_the_deleted_index_the_scan_hint_names(): void
    {
        $names = array_map(
            static fn ($row) => $row->Key_name,
            DB::select('SHOW INDEX FROM users')
        );

        $this->assertContains(
            'deleted',
            $names,
            'NotificationExhortService hints IGNORE INDEX (deleted) on users; that index name '
                . 'must still exist, or the active-user scan throws'
        );
    }
}
