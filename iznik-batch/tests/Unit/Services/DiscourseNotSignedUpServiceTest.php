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

        // The service reads the WHOLE database (every published Freegle group and
        // every active mod), so the test must control that global state — other
        // suites may have committed groups/mods. Unpublish all groups and age out
        // every existing Freegle mod so only the entities each test creates count.
        // (Rolled back by DatabaseTransactions; this is setup, not cleanup.)
        DB::table('groups')->where('publish', 1)->update(['publish' => 0]);
        DB::table('users')
            ->whereIn('id', function ($q) {
                $q->select('memberships.userid')
                    ->from('memberships')
                    ->join('groups', 'groups.id', '=', 'memberships.groupid')
                    ->whereIn('memberships.role', ['Owner', 'Moderator'])
                    ->where('groups.type', 'Freegle');
            })
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

    public function test_reports_and_emails_when_group_unrepresented(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Owner']);

        // No users on Discourse at all → the mod isn't signed up and the group
        // has no active representation.
        $client = $this->mockClient([], fn ($id, $u) => []);

        $result = (new DiscourseNotSignedUpService($client))->run();

        $this->assertFalse($result['skipped']);
        $this->assertSame(1, $result['notrepresented']);
        $this->assertSame(1, $result['notondiscourse']);

        Mail::assertSent(DiscourseReportMail::class, fn ($m) => $m->recipientEmail === 'central@example.com');
        Mail::assertSent(DiscourseReportMail::class, fn ($m) => $m->recipientEmail === 'geeks@example.com');
        Mail::assertSentCount(2);
    }

    public function test_group_represented_when_active_mod_on_discourse(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Owner']);

        $client = $this->mockClient(
            [['id' => 200, 'username' => 'themod']],
            fn ($id, $u) => ['single_sign_on_record' => ['external_id' => $mod->id]],
        );

        $result = (new DiscourseNotSignedUpService($client))->run();

        $this->assertSame(0, $result['notrepresented']);
        $this->assertSame(0, $result['notondiscourse']);

        // Wednesday + nothing unrepresented → no email.
        Mail::assertNothingSent();
    }

    public function test_flags_mod_with_tn_preferred_email(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Owner']);
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
        $this->assertSame(0, $result['notrepresented']);
    }
}
