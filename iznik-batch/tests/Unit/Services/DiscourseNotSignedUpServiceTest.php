<?php

namespace Tests\Unit\Services;

use App\Mail\Housekeeper\DiscourseReportMail;
use App\Services\DiscourseClient;
use App\Services\DiscourseNotSignedUpService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * Behaviour of {@see DiscourseNotSignedUpService} (V1 discourse_not_signed_up.php).
 * The Discourse REST client is mocked. "Now" is frozen to a Wednesday so the
 * Saturday weekly-send branch doesn't make email assertions day-dependent.
 */
class DiscourseNotSignedUpServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 2026-06-10 is a Wednesday (not the Saturday weekly-send day).
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00'));
        config([
            'freegle.discourse.throttle_us' => 0,
            'freegle.mail.centralmods_addr' => 'central@example.com',
            'freegle.mail.geek_alerts_addr' => 'geeks@example.com',
            'freegle.mail.geeks_addr' => 'from@example.com',
        ]);

        // The service reads the WHOLE database (every active mod), so the test must control that
        // global state: age out every existing moderator so only the mods each test creates count.
        // (Rolled back by DatabaseTransactions; this is setup, not cleanup.)
        DB::table('users')
            ->whereIn('systemrole', ['Moderator', 'Support', 'Admin'])
            ->update(['lastaccess' => Carbon::now()->subYears(2)]);
    }

    private function mockClient(array $allUsers, callable $getUser, ?callable $getUserEmail = null): DiscourseClient
    {
        $client = Mockery::mock(DiscourseClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true);
        $client->shouldReceive('getAllUsers')->andReturn($allUsers);
        $client->shouldReceive('getUser')->andReturnUsing($getUser);
        $client->shouldReceive('getUserEmail')->andReturnUsing($getUserEmail ?? fn ($u) => $u.'@example.com');

        return $client;
    }

    public function test_skips_when_not_configured(): void
    {
        $client = Mockery::mock(DiscourseClient::class);
        $client->shouldReceive('isConfigured')->andReturn(false);
        $client->shouldNotReceive('getAllUsers');

        $result = (new DiscourseNotSignedUpService($client))->run();

        $this->assertTrue($result['skipped']);
    }

    public function test_reports_and_emails_on_a_saturday_when_a_mod_is_not_signed_up(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-10-03 10:00:00'); // a Saturday

        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        // No users on Discourse at all → the mod isn't signed up.
        $client = $this->mockClient([], fn ($id, $u) => []);

        $result = (new DiscourseNotSignedUpService($client))->run();

        $this->assertFalse($result['skipped']);
        $this->assertSame(1, $result['notondiscourse']);

        Mail::assertSent(DiscourseReportMail::class, fn ($m) => $m->recipientEmail === 'central@example.com');
        Mail::assertSent(DiscourseReportMail::class, fn ($m) => $m->recipientEmail === 'geeks@example.com');
        Mail::assertSentCount(2);
    }

    public function test_no_email_midweek_when_every_active_mod_is_on_discourse(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-09-30 10:00:00'); // a Wednesday

        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);

        $client = $this->mockClient(
            [['id' => 200, 'username' => 'themod']],
            fn ($id, $u) => ['single_sign_on_record' => ['external_id' => $mod->id]],
        );

        $result = (new DiscourseNotSignedUpService($client))->run();

        $this->assertSame(0, $result['notondiscourse']);

        // Wednesday: no email.
        Mail::assertNothingSent();
    }

    public function test_flags_mod_with_tn_preferred_email(): void
    {
        Mail::fake();

        $mod = $this->createTestUser();
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => 'Moderator', 'emailfrequency' => -1]);
        DB::table('users_emails')->insert([
            'userid' => $mod->id,
            'email' => 'someone@user.trashnothing.com',
            'preferred' => 1,
        ]);

        $client = $this->mockClient(
            [['id' => 201, 'username' => 'tnmod']],
            fn ($id, $u) => ['single_sign_on_record' => ['external_id' => $mod->id]],
        );

        $result = (new DiscourseNotSignedUpService($client))->run();

        $this->assertSame(1, $result['tnpreferred']);
    }
}
