<?php

namespace Tests\Feature\Message;

use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Services\FreegleApiClient;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Orchestration test for `matches:notify`: with the mail send mocked out, verify
 * it records what it mailed (the per-post ledger + the per-user cooldown) and
 * that a dry run records nothing.
 */
class NotifyMatchedPostsCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        FreegleApiClient::clearFake();
        parent::tearDown();
    }

    private function seedFixture(): array
    {
        $group = $this->createTestGroup();

        $recipient = $this->createTestUser(['lastaccess' => now(), 'relevantallowed' => 1, 'lastrelevantcheck' => null]);
        $this->createTestUserEmail($recipient, ['preferred' => 1]);
        $offerer = $this->createTestUser(['lastaccess' => now(), 'relevantallowed' => 1, 'lastrelevantcheck' => null]);
        $this->createTestUserEmail($offerer, ['preferred' => 1]);

        $wanted = $this->createTestMessage($recipient, $group, ['type' => 'Wanted', 'subject' => 'WANTED: Drill (Leeds)', 'arrival' => now()]);
        DB::statement(
            'INSERT INTO messages_spatial (msgid, groupid, msgtype, successful, promised, arrival, point)
             VALUES (?, ?, ?, 0, 0, ?, ST_GeomFromText(?, 3857))',
            [$wanted->id, $group->id, 'Wanted', now(), sprintf('POINT(%F %F)', $group->lng, $group->lat)]
        );
        DB::statement('INSERT INTO messages_embeddings (msgid, subject_embedding, model_version) VALUES (?, ?, ?)',
            [$wanted->id, str_repeat("\0", 1024), 'test']);

        $offer = $this->createTestMessage($offerer, $group, ['type' => 'Offer', 'subject' => 'OFFER: Drill (Leeds)', 'arrival' => now()->subDay()]);

        FreegleApiClient::fake([
            ['body' => [['id' => $offer->id, 'score' => 0.8, 'groupid' => $group->id, 'lat' => 51.5, 'lng' => -0.1]]],
        ]);

        return [$recipient, $wanted, $offerer, $offer];
    }

    public function test_records_ledger_and_cooldown_when_it_mails(): void
    {
        [$recipient, $wanted, $offerer, $offer] = $this->seedFixture();

        $this->mock(EmailSpoolerService::class, function ($m) {
            $m->shouldReceive('spool')->andReturn('spool-id');
        });
        $this->mock(PushNotificationService::class, function ($m) {
            $m->shouldReceive('notifyUser');
        });

        $this->artisan('matches:notify')->assertExitCode(0);

        // The matched offer is recorded as mailed to the recipient (dedup ledger).
        $this->assertDatabaseHas('messages_matched_notified', [
            'msgid' => $offer->id,
            'userid' => $recipient->id,
        ]);
        // Cooldown watermark bumped.
        $this->assertNotNull($recipient->fresh()->lastrelevantcheck);
    }

    public function test_dry_run_records_nothing(): void
    {
        [$recipient, $wanted, $offerer, $offer] = $this->seedFixture();

        $this->mock(EmailSpoolerService::class, function ($m) {
            $m->shouldReceive('spool')->never();
        });
        $this->mock(PushNotificationService::class, function ($m) {
            $m->shouldReceive('notifyUser')->never();
        });

        $this->artisan('matches:notify', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('messages_matched_notified', [
            'msgid' => $offer->id,
            'userid' => $recipient->id,
        ]);
        $this->assertDatabaseMissing('users_notifications', [
            'touser' => $recipient->id,
            'type' => 'MatchedPost',
        ]);
        $this->assertNull($recipient->fresh()->lastrelevantcheck);
    }

    public function test_creates_inapp_notification_and_fires_push(): void
    {
        [$recipient, $wanted, $offerer, $offer] = $this->seedFixture();

        $pushed = [];
        $this->mock(EmailSpoolerService::class, function ($m) {
            $m->shouldReceive('spool')->andReturn('spool-id');
        });
        $this->mock(PushNotificationService::class, function ($m) use (&$pushed) {
            $m->shouldReceive('notifyUser')->andReturnUsing(function ($uid) use (&$pushed) {
                $pushed[] = (int) $uid;

                return 1;
            });
        });

        $this->artisan('matches:notify')->assertExitCode(0);

        // In-app bell notification created for the recipient, pointing at the match.
        $this->assertDatabaseHas('users_notifications', [
            'touser' => $recipient->id,
            'type' => 'MatchedPost',
            'url' => '/message/' . $offer->id,
            'seen' => 0,
        ]);
        // And a device push was fired for that recipient.
        $this->assertContains((int) $recipient->id, $pushed);
    }

    /**
     * Run matches:notify with the recipient's account changed by $attributes, and assert that the
     * recipient still gets the in-app notification but is not sent the matched-posts email.
     */
    private function assertRecipientNotEmailed(array $attributes): void
    {
        [$recipient] = $this->seedFixture();
        $recipient->forceFill($attributes)->save();

        $emailed = [];
        $this->mock(EmailSpoolerService::class, function ($m) use (&$emailed) {
            $m->shouldReceive('spool')->andReturnUsing(function ($mailable) use (&$emailed) {
                $emailed[] = (int) $mailable->user->id;

                return 'spool-id';
            });
        });
        $this->mock(PushNotificationService::class, function ($m) {
            $m->shouldReceive('notifyUser');
        });

        $this->artisan('matches:notify')->assertExitCode(0);

        $this->assertDatabaseHas('users_notifications', [
            'touser' => $recipient->id,
            'type' => 'MatchedPost',
        ]);
        $this->assertNotContains((int) $recipient->id, $emailed, 'a member who may not be emailed must not get the matched-posts mail');
    }

    public function test_does_not_email_a_member_who_turned_all_our_mail_off(): void
    {
        $this->assertRecipientNotEmailed(['settings' => ['simplemail' => 'None']]);
    }

    public function test_does_not_email_a_member_whose_address_is_bouncing(): void
    {
        $this->assertRecipientNotEmailed(['bouncing' => 1]);
    }

    public function test_does_not_email_a_member_who_is_on_holiday(): void
    {
        $this->assertRecipientNotEmailed(['onholidaytill' => now()->addDays(10)->toDateString()]);
    }
}
