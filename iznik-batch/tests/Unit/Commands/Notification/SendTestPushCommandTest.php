<?php

namespace Tests\Unit\Commands\Notification;

use App\Services\PushNotificationService;
use Tests\TestCase;

class SendTestPushCommandTest extends TestCase
{
    public function test_send_notification_with_modtools_app(): void
    {
        $user = $this->createTestUser();
        $email = 'test' . $user->id . '@test.com';

        $this->createTestPushNotification($user->id, 'ModTools');

        $service = $this->createMock(PushNotificationService::class);
        $service->expects($this->once())->method('notify')
            ->with($user->id, true)
            ->willReturn(1);
        $this->app->instance(PushNotificationService::class, $service);

        $this->artisan('push:test-notification', [
            '--email' => $email,
            '--app' => 'mt',
        ])
            ->expectsOutputToContain("user #{$user->id}")
            ->expectsOutputToContain('Found 1 subscription(s)')
            ->expectsOutputToContain('Successfully sent 1 notification(s)')
            ->assertExitCode(0);
    }

    public function test_send_notification_with_freegle_direct_app(): void
    {
        $user = $this->createTestUser();
        $email = 'test' . $user->id . '@test.com';

        $this->createTestPushNotification($user->id, 'User');

        $service = $this->createMock(PushNotificationService::class);
        $service->expects($this->once())->method('notify')
            ->with($user->id, false)
            ->willReturn(1);
        $this->app->instance(PushNotificationService::class, $service);

        $this->artisan('push:test-notification', [
            '--email' => $email,
            '--app' => 'fd',
        ])
            ->expectsOutputToContain("user #{$user->id}")
            ->expectsOutputToContain('Found 1 subscription(s)')
            ->expectsOutputToContain('Successfully sent 1 notification(s)')
            ->assertExitCode(0);
    }

    public function test_find_user_by_id(): void
    {
        $user = $this->createTestUser();
        $this->createTestPushNotification($user->id, 'ModTools');

        $service = $this->createMock(PushNotificationService::class);
        $service->method('notify')->willReturn(1);
        $this->app->instance(PushNotificationService::class, $service);

        $this->artisan('push:test-notification', [
            '--id' => $user->id,
            '--app' => 'mt',
        ])
            ->expectsOutputToContain("user #{$user->id}")
            ->assertExitCode(0);
    }

    public function test_fails_when_no_subscriptions_found(): void
    {
        $user = $this->createTestUser();
        $email = 'test' . $user->id . '@test.com';

        $this->artisan('push:test-notification', [
            '--email' => $email,
            '--app' => 'mt',
        ])
            ->expectsOutputToContain('No push notification subscriptions found')
            ->assertExitCode(1);
    }

    public function test_fails_when_user_not_found(): void
    {
        $this->artisan('push:test-notification', [
            '--email' => 'nonexistent@example.com',
            '--app' => 'mt',
        ])
            ->expectsOutputToContain('No user found with email: nonexistent@example.com')
            ->assertExitCode(1);
    }

    public function test_fails_when_neither_email_nor_id_provided(): void
    {
        $this->artisan('push:test-notification')
            ->expectsOutputToContain('--email or --id is required')
            ->assertExitCode(1);
    }

    public function test_fails_with_invalid_app_option(): void
    {
        $user = $this->createTestUser();
        $email = 'test' . $user->id . '@test.com';

        $this->artisan('push:test-notification', [
            '--email' => $email,
            '--app' => 'invalid',
        ])
            ->expectsOutputToContain("Invalid --app value: invalid")
            ->assertExitCode(1);
    }

    public function test_default_app_is_modtools(): void
    {
        $user = $this->createTestUser();
        $email = 'test' . $user->id . '@test.com';
        $this->createTestPushNotification($user->id, 'ModTools');

        $service = $this->createMock(PushNotificationService::class);
        $service->expects($this->once())->method('notify')
            ->with($user->id, true)
            ->willReturn(1);
        $this->app->instance(PushNotificationService::class, $service);

        $this->artisan('push:test-notification', [
            '--email' => $email,
            // No --app option; should default to mt (modtools=true)
        ])
            ->assertExitCode(0);
    }

    public function test_lists_subscription_tokens(): void
    {
        $user = $this->createTestUser();
        $email = 'test' . $user->id . '@test.com';
        $this->createTestPushNotification($user->id, 'ModTools');

        $service = $this->createMock(PushNotificationService::class);
        $service->method('notify')->willReturn(1);
        $this->app->instance(PushNotificationService::class, $service);

        $this->artisan('push:test-notification', [
            '--email' => $email,
            '--app' => 'mt',
        ])
            ->expectsOutputToContain('FCMAndroid')
            ->expectsOutputToContain('...')
            ->assertExitCode(0);
    }

    public function test_fails_when_notify_returns_zero(): void
    {
        $user = $this->createTestUser();
        $email = 'test' . $user->id . '@test.com';
        $this->createTestPushNotification($user->id, 'ModTools');

        $service = $this->createMock(PushNotificationService::class);
        $service->method('notify')->willReturn(0);
        $this->app->instance(PushNotificationService::class, $service);

        $this->artisan('push:test-notification', [
            '--email' => $email,
            '--app' => 'mt',
        ])
            ->expectsOutputToContain('Failed to send notifications')
            ->assertExitCode(1);
    }

    private function createTestPushNotification(int $userId, string $apptype): void
    {
        \DB::table('users_push_notifications')->insert([
            'userid' => $userId,
            'apptype' => $apptype,
            'type' => 'FCMAndroid',
            'subscription' => 'test_token_' . bin2hex(random_bytes(16)),
            'created' => now(),
        ]);
    }
}
