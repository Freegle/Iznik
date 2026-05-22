<?php

namespace Tests\Feature\Notification;

use App\Services\NotificationExhortService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExhortUsersCommandTest extends TestCase
{
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
}
