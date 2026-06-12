<?php

namespace Tests\Unit\Services;

use App\Mail\Housekeeper\DiscourseReportMail;
use App\Services\DiscourseCheckUsersService;
use App\Services\DiscourseClient;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Behaviour of {@see DiscourseCheckUsersService} (V1 discourse_checkusers.php).
 * The Discourse REST client is mocked; the focus is mod detection, the
 * announcements-watch fix, bio updates, and the report email routing.
 */
class DiscourseCheckUsersServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freegle.discourse.throttle_us' => 0,
            'freegle.discourse.announcements_category_id' => 7,
            'freegle.mail.centralmods_addr' => 'central@example.com',
            'freegle.mail.geek_alerts_addr' => 'geeks@example.com',
            'freegle.mail.geeks_addr' => 'from@example.com',
        ]);
    }

    public function test_skips_when_not_configured(): void
    {
        $client = Mockery::mock(DiscourseClient::class);
        $client->shouldReceive('isConfigured')->andReturn(false);
        $client->shouldNotReceive('getAllUsers');

        $result = (new DiscourseCheckUsersService($client))->run();

        $this->assertTrue($result['skipped']);
    }

    public function test_audits_users_fixes_watching_and_emails_when_non_mod_found(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $modUser = $this->createTestUser();
        $this->createMembership($modUser, $group, ['role' => 'Owner']);
        $plainUser = $this->createTestUser(); // exists but moderates nothing

        $client = Mockery::mock(DiscourseClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true);
        $client->shouldReceive('getAllUsers')->andReturn([
            ['id' => 101, 'username' => 'moduser', 'last_posted_at' => null, 'last_seen_at' => null],
            ['id' => 102, 'username' => 'notmoduser', 'last_posted_at' => null, 'last_seen_at' => null],
            ['id' => 1, 'username' => 'freeglegeeks'],
        ]);
        $client->shouldReceive('getUser')->andReturnUsing(function ($id, $username) use ($modUser, $plainUser) {
            return [
                'bounce_score' => 0,
                'last_emailed_at' => null,
                'single_sign_on_record' => [
                    'external_id' => $username === 'moduser' ? $modUser->id : $plainUser->id,
                ],
            ];
        });
        $client->shouldReceive('getUserEmail')->andReturnUsing(fn ($u) => $u.'@example.com');
        $client->shouldReceive('getUserPublic')->andReturn([
            'watched_category_ids' => [],
            'watched_first_post_category_ids' => [],
            'tracked_category_ids' => [5],
            'user_option' => ['mailing_list_mode' => false, 'email_digests' => true],
            'bio_raw' => '',
        ]);
        // Both users are missing the announcements category → should be added.
        $client->shouldReceive('setWatchCategory')->twice();
        // Both users' bios differ from '' → should be set.
        $client->shouldReceive('setBio')->twice();

        $result = (new DiscourseCheckUsersService($client))->run();

        $this->assertFalse($result['skipped']);
        $this->assertSame(1, $result['system']);
        $this->assertSame(1, $result['ismod']);
        $this->assertSame(1, $result['notmod']);
        $this->assertSame(0, $result['notuser']);
        $this->assertSame(2, $result['notonannouncements']);
        $this->assertSame(2, $result['bioupdatedcount']);

        // A non-mod was found → central mods + geeks both emailed.
        Mail::assertSent(DiscourseReportMail::class, fn ($m) => $m->recipientEmail === 'central@example.com'
            && $m->emailSubject === 'Discourse checkuser USERS TO CHECK');
        Mail::assertSent(DiscourseReportMail::class, fn ($m) => $m->recipientEmail === 'geeks@example.com');
        Mail::assertSentCount(2);
    }
}
