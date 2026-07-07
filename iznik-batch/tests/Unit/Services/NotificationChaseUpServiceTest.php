<?php

namespace Tests\Unit\Services;

use App\Mail\Notification\ChaseUpMail;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationChaseUpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationChaseUpServiceTest extends TestCase
{
    protected NotificationChaseUpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationChaseUpService();
        Mail::fake();
        // Reset feature flags before each test — config() changes persist across tests.
        config(['freegle.mail.enabled_types' => 'NotificationChaseUp']);
    }

    // -----------------------------------------------------------------------
    // Helper: create a users_notifications row
    // -----------------------------------------------------------------------

    private function createNotification(User $toUser, User $fromUser, array $attributes = []): object
    {
        $id = DB::table('users_notifications')->insertGetId(array_merge([
            'fromuser'   => $fromUser->id,
            'touser'     => $toUser->id,
            'type'       => 'CommentOnYourPost',
            'newsfeedid' => null,
            'url'        => null,
            'title'      => null,
            'text'       => null,
            'timestamp'  => now()->subMinutes(10), // old enough (> 5 min)
            'seen'       => 0,
            'mailed'     => 0,
        ], $attributes));

        return DB::table('users_notifications')->where('id', $id)->first();
    }

    private function createNewsfeedItem(array $attributes = []): object
    {
        $id = DB::table('newsfeed')->insertGetId(array_merge([
            'userid'    => null,
            'type'      => 'Message',
            'message'   => 'Test newsfeed message',
            'timestamp' => now(),
            'deleted'   => null,
            'replyto'   => null,
            'position'  => DB::raw("ST_GeomFromText('POINT(0 0)', 3857)"),
        ], $attributes));

        return DB::table('newsfeed')->where('id', $id)->first();
    }

    // -----------------------------------------------------------------------
    // Feature flag tests
    // -----------------------------------------------------------------------

    public function test_returns_zero_when_email_type_disabled(): void
    {
        // Disable the email type
        config(['freegle.mail.enabled_types' => '']);

        $user = $this->createTestUser();
        $sender = $this->createTestUser();
        $this->createNotification($user, $sender);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------------
    // No pending notifications
    // -----------------------------------------------------------------------

    public function test_sends_no_emails_when_no_pending_notifications(): void
    {
        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------------
    // Happy path: sends email for pending notification
    // -----------------------------------------------------------------------

    public function test_sends_email_for_user_with_pending_comment_notification(): void
    {
        $user = $this->createTestUser(['lastaccess' => now()]);
        $sender = $this->createTestUser();

        $newsfeed = $this->createNewsfeedItem(['message' => 'Hello world post']);
        $this->createNotification($user, $sender, [
            'type'       => 'CommentOnYourPost',
            'newsfeedid' => $newsfeed->id,
            'timestamp'  => now()->subMinutes(10),
        ]);

        $count = $this->service->sendEmails();

        $this->assertEquals(1, $count);
        Mail::assertSent(ChaseUpMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email_preferred);
        });
    }

    // -----------------------------------------------------------------------
    // Time window filtering
    // -----------------------------------------------------------------------

    public function test_does_not_send_for_too_recent_notifications(): void
    {
        $user = $this->createTestUser();
        $sender = $this->createTestUser();

        // Only 2 minutes old — too recent (V1 default: 5 minutes)
        $this->createNotification($user, $sender, [
            'timestamp' => now()->subMinutes(2),
        ]);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_does_not_send_for_too_old_notifications(): void
    {
        $user = $this->createTestUser();
        $sender = $this->createTestUser();

        // 25 hours old — outside the 24-hour window
        $this->createNotification($user, $sender, [
            'timestamp' => now()->subHours(25),
        ]);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------------
    // Already mailed / already seen
    // -----------------------------------------------------------------------

    public function test_does_not_send_already_mailed_notifications(): void
    {
        $user = $this->createTestUser();
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender, ['mailed' => 1]);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------------
    // Excluded notification types
    // -----------------------------------------------------------------------

    public function test_excludes_try_feed_notifications(): void
    {
        $user = $this->createTestUser();
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender, ['type' => 'TryFeed']);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_excludes_about_me_notifications(): void
    {
        $user = $this->createTestUser();
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender, ['type' => 'AboutMe']);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_excludes_gift_aid_notifications(): void
    {
        $user = $this->createTestUser();
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender, ['type' => 'GiftAid']);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_excludes_open_posts_notifications(): void
    {
        $user = $this->createTestUser();
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender, ['type' => 'OpenPosts']);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------------
    // User eligibility checks
    // -----------------------------------------------------------------------

    public function test_does_not_send_to_deleted_users(): void
    {
        $user = $this->createTestUser(['deleted' => now()]);
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_does_not_send_when_simplemail_is_none(): void
    {
        $user = $this->createTestUser([
            'settings' => ['simplemail' => 'None'],
        ]);
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_does_not_send_when_notificationmails_is_false(): void
    {
        $user = $this->createTestUser([
            'settings' => ['notificationmails' => false],
        ]);
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_does_not_send_to_bouncing_users(): void
    {
        $user = $this->createTestUser(['bouncing' => 1]);
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_does_not_send_to_user_on_holiday(): void
    {
        $user = $this->createTestUser(['onholidaytill' => now()->addDays(3)]);
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_does_not_send_to_inactive_user(): void
    {
        // Last active > 182 days ago
        $user = $this->createTestUser(['lastaccess' => now()->subDays(200)]);
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------------
    // Spam filtering
    // -----------------------------------------------------------------------

    public function test_excludes_notifications_from_spammers(): void
    {
        $user = $this->createTestUser();
        $spammer = $this->createTestUser();

        // Mark the from-user as a spammer
        DB::table('spam_users')->insert([
            'userid'     => $spammer->id,
            'byuserid'   => $user->id,
            'collection' => 'Spammer',
        ]);

        $this->createNotification($user, $spammer);

        $count = $this->service->sendEmails();

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------------
    // Marks notifications as mailed
    // -----------------------------------------------------------------------

    public function test_marks_notifications_as_mailed_after_sending(): void
    {
        $user = $this->createTestUser(['lastaccess' => now()]);
        $sender = $this->createTestUser();

        $notif = $this->createNotification($user, $sender);

        $this->service->sendEmails();

        $updated = DB::table('users_notifications')->where('id', $notif->id)->first();
        $this->assertEquals(1, $updated->mailed);
    }

    public function test_does_not_send_to_already_mailed_users(): void
    {
        $user = $this->createTestUser(['lastaccess' => now()]);
        $sender = $this->createTestUser();

        // Create notification, run service (marks as mailed)
        $this->createNotification($user, $sender);
        $this->service->sendEmails();
        Mail::assertSent(ChaseUpMail::class, 1);

        // Run again — should NOT resend
        $this->service->sendEmails();
        Mail::assertSent(ChaseUpMail::class, 1); // still only 1 sent total
    }

    // -----------------------------------------------------------------------
    // Dry run
    // -----------------------------------------------------------------------

    public function test_dry_run_does_not_send_emails(): void
    {
        $user = $this->createTestUser(['lastaccess' => now()]);
        $sender = $this->createTestUser();

        $notif = $this->createNotification($user, $sender);

        $count = $this->service->sendEmails(dryRun: true);

        $this->assertGreaterThan(0, $count);
        Mail::assertNothingSent();

        // Should NOT have marked as mailed
        $updated = DB::table('users_notifications')->where('id', $notif->id)->first();
        $this->assertEquals(0, $updated->mailed);
    }

    /**
     * Transient SMTP failures (mail-host timeout/closed-unexpectedly) must NOT
     * abort the per-user loop — SafeMail catches them and the next user still
     * gets processed. Without this, the 2026-05-15 07:31 mail-host timeout
     * killed the whole chaseup run.
     */
    public function test_transient_smtp_failure_does_not_abort_run(): void
    {
        // Two eligible users with pending notifications.
        $u1 = $this->createTestUser(['lastaccess' => now()]);
        $u2 = $this->createTestUser(['lastaccess' => now()]);
        $sender = $this->createTestUser();
        $this->createNotification($u1, $sender);
        $this->createNotification($u2, $sender);

        // Stub the Mail facade to throw a transient TransportException on the
        // first send call and succeed on the second. Mail::fake() short-circuits
        // the whole pipeline, so we mock the manager directly.
        $pending = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
        $pending->shouldReceive('send')
            ->andReturnUsing(function () {
                static $first = true;
                if ($first) {
                    $first = false;
                    throw new \Symfony\Component\Mailer\Exception\TransportException(
                        'Connection to "mail-host:25" timed out.'
                    );
                }
            });

        Mail::shouldReceive('to')->andReturn($pending);

        // Should not throw — SafeMail handles the transient failure and the
        // second user gets processed.
        $count = $this->service->sendEmails();

        $this->assertEquals(2, $count, 'both users should be counted as attempted');
    }

    // -----------------------------------------------------------------------
    // Subject line generation
    // -----------------------------------------------------------------------

    public function test_subject_for_comment_on_your_post(): void
    {
        $subject = $this->service->getNotifTitle([[
            'type'     => 'CommentOnYourPost',
            'fromname' => 'Alice',
            'newsfeed' => ['type' => 'Message', 'message' => 'Hello world'],
            'title'    => null,
            'seen'     => false,
        ]]);

        $this->assertStringContainsString('Alice', $subject);
        $this->assertStringContainsString('commented', $subject);
    }

    public function test_subject_for_comment_on_comment(): void
    {
        $subject = $this->service->getNotifTitle([[
            'type'     => 'CommentOnCommented',
            'fromname' => 'Bob',
            'newsfeed' => ['type' => 'Message', 'message' => 'Reply text'],
            'title'    => null,
            'seen'     => false,
        ]]);

        $this->assertStringContainsString('Bob', $subject);
        $this->assertStringContainsString('replied', $subject);
    }

    public function test_subject_for_loved_post(): void
    {
        $subject = $this->service->getNotifTitle([[
            'type'     => 'LovedPost',
            'fromname' => 'Carol',
            'newsfeed' => ['type' => 'Message', 'message' => 'Nice item'],
            'title'    => null,
            'seen'     => false,
        ]]);

        $this->assertStringContainsString('Carol', $subject);
        $this->assertStringContainsString('loved', $subject);
    }

    public function test_subject_for_multiple_notifications_appends_count(): void
    {
        $subject = $this->service->getNotifTitle([
            ['type' => 'CommentOnYourPost', 'fromname' => 'Alice', 'newsfeed' => ['type' => 'Message', 'message' => 'Post 1'], 'title' => null, 'seen' => false],
            ['type' => 'CommentOnYourPost', 'fromname' => 'Bob', 'newsfeed' => ['type' => 'Message', 'message' => 'Post 2'], 'title' => null, 'seen' => false],
            ['type' => 'LovedPost', 'fromname' => 'Carol', 'newsfeed' => ['type' => 'Message', 'message' => 'Post 3'], 'title' => null, 'seen' => false],
        ]);

        $this->assertStringContainsString('+', $subject);
        $this->assertStringContainsString('more', $subject);
    }

    public function test_subject_for_exhort_uses_title(): void
    {
        $subject = $this->service->getNotifTitle([[
            'type'     => 'Exhort',
            'fromname' => 'Freegle',
            'newsfeed' => null,
            'title'    => 'Tell us your story!',
            'seen'     => false,
        ]]);

        $this->assertStringContainsString('Tell us your story!', $subject);
    }

    public function test_subject_for_loved_comment(): void
    {
        $subject = $this->service->getNotifTitle([[
            'type'     => 'LovedComment',
            'fromname' => 'Dave',
            'newsfeed' => ['type' => 'Message', 'message' => 'Great comment'],
            'title'    => null,
            'seen'     => false,
        ]]);

        $this->assertStringContainsString('Dave', $subject);
        $this->assertStringContainsString('loved', $subject);
    }

    public function test_subject_for_membership_pending(): void
    {
        $subject = $this->service->getNotifTitle([[
            'type'     => 'MembershipPending',
            'fromname' => '',
            'newsfeed' => null,
            'title'    => null,
            'url'      => 'Freegle Bristol',
            'seen'     => false,
        ]]);

        $this->assertStringContainsString('Freegle Bristol', $subject);
        $this->assertStringContainsString('approval', $subject);
    }

    public function test_subject_for_membership_approved(): void
    {
        $subject = $this->service->getNotifTitle([[
            'type'     => 'MembershipApproved',
            'fromname' => '',
            'newsfeed' => null,
            'title'    => null,
            'url'      => 'Freegle Bristol',
            'seen'     => false,
        ]]);

        $this->assertStringContainsString('Freegle Bristol', $subject);
        $this->assertStringContainsString('approved', $subject);
    }

    public function test_subject_for_membership_rejected(): void
    {
        $subject = $this->service->getNotifTitle([[
            'type'     => 'MembershipRejected',
            'fromname' => '',
            'newsfeed' => null,
            'title'    => null,
            'url'      => 'Freegle Bristol',
            'seen'     => false,
        ]]);

        $this->assertStringContainsString('Freegle Bristol', $subject);
        $this->assertStringContainsString('rejected', $subject);
    }

    // -----------------------------------------------------------------------
    // Membership notification types
    // -----------------------------------------------------------------------

    public function test_sends_email_for_membership_pending_notification(): void
    {
        $user   = $this->createTestUser(['lastaccess' => now()]);
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender, [
            'type' => 'MembershipPending',
            'url'  => 'Freegle Bristol',
        ]);

        $count = $this->service->sendEmails();

        $this->assertEquals(1, $count);
        Mail::assertSent(ChaseUpMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email_preferred);
        });
    }

    public function test_sends_email_for_membership_approved_notification(): void
    {
        $user   = $this->createTestUser(['lastaccess' => now()]);
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender, [
            'type' => 'MembershipApproved',
            'url'  => 'Freegle Bristol',
        ]);

        $count = $this->service->sendEmails();

        $this->assertEquals(1, $count);
        Mail::assertSent(ChaseUpMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email_preferred);
        });
    }

    public function test_sends_email_for_membership_rejected_notification(): void
    {
        $user   = $this->createTestUser(['lastaccess' => now()]);
        $sender = $this->createTestUser();

        $this->createNotification($user, $sender, [
            'type' => 'MembershipRejected',
            'url'  => 'Freegle Bristol',
        ]);

        $count = $this->service->sendEmails();

        $this->assertEquals(1, $count);
        Mail::assertSent(ChaseUpMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email_preferred);
        });
    }

    // -----------------------------------------------------------------------
    // Already-mailed notifications must not appear in email body
    // -----------------------------------------------------------------------

    public function test_email_body_excludes_already_mailed_notifications(): void
    {
        $user   = $this->createTestUser(['lastaccess' => now()]);
        $sender = $this->createTestUser();

        // Old notification already sent in a previous email (mailed=1, never seen)
        $this->createNotification($user, $sender, [
            'mailed'    => 1,
            'timestamp' => now()->subHours(12),
        ]);

        // New notification that triggers this send
        $this->createNotification($user, $sender, [
            'mailed'    => 0,
            'timestamp' => now()->subMinutes(10),
        ]);

        $this->service->sendEmails();

        Mail::assertSent(ChaseUpMail::class, function (ChaseUpMail $mail) {
            return count($mail->notifications) === 1;
        });
    }

    // -----------------------------------------------------------------------
    // User-specific filtering
    // -----------------------------------------------------------------------

    public function test_can_filter_by_specific_user_id(): void
    {
        $user1 = $this->createTestUser(['lastaccess' => now()]);
        $user2 = $this->createTestUser(['lastaccess' => now()]);
        $sender = $this->createTestUser();

        $this->createNotification($user1, $sender);
        $this->createNotification($user2, $sender);

        // Only process user1
        $count = $this->service->sendEmails(userId: $user1->id);

        $this->assertEquals(1, $count);
        Mail::assertSent(ChaseUpMail::class, function ($mail) use ($user1) {
            return $mail->hasTo($user1->email_preferred);
        });
        Mail::assertNotSent(ChaseUpMail::class, function ($mail) use ($user2) {
            return $mail->hasTo($user2->email_preferred);
        });
    }
}
