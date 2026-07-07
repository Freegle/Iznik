<?php

namespace Tests\Unit\Models;

use App\Models\Notification;
use App\Models\User;
use Tests\TestCase;

class NotificationModelTest extends TestCase
{
    public function test_notification_can_be_created(): void
    {
        $user = $this->createTestUser();

        $notification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now(),
            'type' => 'TryFeed',
        ]);

        $this->assertDatabaseHas('users_notifications', [
            'id' => $notification->id,
            'touser' => $user->id,
        ]);
    }

    public function test_user_relationship(): void
    {
        $user = $this->createTestUser();

        $notification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now(),
            'type' => 'TryFeed',
        ]);

        $this->assertInstanceOf(User::class, $notification->user);
        $this->assertEquals($user->id, $notification->user->id);
    }

    public function test_from_user_relationship(): void
    {
        $user = $this->createTestUser();
        $fromUser = $this->createTestUser();

        $notification = Notification::create([
            'touser' => $user->id,
            'fromuser' => $fromUser->id,
            'timestamp' => now(),
            'type' => 'CommentOnYourPost',
        ]);

        $this->assertInstanceOf(User::class, $notification->fromUser);
        $this->assertEquals($fromUser->id, $notification->fromUser->id);
    }

    public function test_unseen_scope(): void
    {
        $user = $this->createTestUser();

        // Create unseen notification.
        $unseenNotification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now(),
            'type' => 'TryFeed',
            'seen' => false,
        ]);

        // Create seen notification.
        $seenNotification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now(),
            'type' => 'TryFeed',
            'seen' => true,
        ]);

        $unseenNotifications = Notification::unseen()->get();

        $this->assertTrue($unseenNotifications->contains('id', $unseenNotification->id));
        $this->assertFalse($unseenNotifications->contains('id', $seenNotification->id));
    }

    public function test_recent_scope(): void
    {
        $user = $this->createTestUser();

        // Create recent notification.
        $recentNotification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now()->subHours(2),
            'type' => 'TryFeed',
        ]);

        // Create old notification.
        $oldNotification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now()->subDays(2),
            'type' => 'TryFeed',
        ]);

        $recentNotifications = Notification::recent(24)->get();

        $this->assertTrue($recentNotifications->contains('id', $recentNotification->id));
        $this->assertFalse($recentNotifications->contains('id', $oldNotification->id));
    }

    public function test_recent_scope_with_custom_hours(): void
    {
        $user = $this->createTestUser();

        // Create notification 3 hours ago.
        $notification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now()->subHours(3),
            'type' => 'TryFeed',
        ]);

        // With 2 hours limit, should not include.
        $recentNotifications = Notification::recent(2)->get();
        $this->assertFalse($recentNotifications->contains('id', $notification->id));

        // With 4 hours limit, should include.
        $recentNotifications = Notification::recent(4)->get();
        $this->assertTrue($recentNotifications->contains('id', $notification->id));
    }

    public function test_in_time_range_scope(): void
    {
        $user = $this->createTestUser();

        // Create notification in range.
        $inRangeNotification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now()->subHours(2),
            'type' => 'TryFeed',
        ]);

        // Create notification outside range.
        $outOfRangeNotification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now()->subDays(2),
            'type' => 'TryFeed',
        ]);

        $from = now()->subHours(4)->toDateTimeString();
        $to = now()->toDateTimeString();

        $notifications = Notification::inTimeRange($from, $to)->get();

        $this->assertTrue($notifications->contains('id', $inRangeNotification->id));
        $this->assertFalse($notifications->contains('id', $outOfRangeNotification->id));
    }

    public function test_timestamp_is_cast_to_datetime(): void
    {
        $user = $this->createTestUser();

        $notification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now(),
            'type' => 'TryFeed',
        ]);

        $notification->refresh();

        $this->assertInstanceOf(\Carbon\Carbon::class, $notification->timestamp);
    }

    public function test_seen_is_cast_to_boolean(): void
    {
        $user = $this->createTestUser();

        $notification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now(),
            'type' => 'TryFeed',
            'seen' => true,
        ]);

        $notification->refresh();

        $this->assertTrue($notification->seen);
    }

    public function test_seen_defaults_to_false(): void
    {
        $user = $this->createTestUser();

        $notification = Notification::create([
            'touser' => $user->id,
            'timestamp' => now(),
            'type' => 'TryFeed',
        ]);

        $notification->refresh();

        $this->assertFalse($notification->seen);
    }
}
