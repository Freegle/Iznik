<?php

namespace Tests\Feature\Mail;

use App\Models\Group;
use App\Models\Membership;
use App\Services\EngageEmailService;
use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * EngageEmailService::processEngageEmails() under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.7). Ordinary (no lockdown) behaviour is covered
 * by EngageEmailsCommandTest; this file is only the lockdown branch: a held member gets no
 * mail generated and no `engage` row written (so the RESEND_INTERVAL_DAYS clock does not
 * start against them), and lifting sends normally on the next run.
 */
class EngageEmailServiceLockdownTest extends TestCase
{
    use IsolatedSpoolDirectory;

    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        DB::table('lockdown_acks')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    private function createFreegleGroup(): Group
    {
        return Group::create([
            'nameshort' => 'TestEngageLockdown_' . uniqid(),
            'type' => Group::TYPE_FREEGLE,
            'publish' => 1,
            'onmap' => 1,
            'onhere' => 1,
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);
    }

    private function createUserWithGroupMembership(array $userAttributes = []): object
    {
        $user = $this->createTestUser($userAttributes);
        $group = $this->createFreegleGroup();
        DB::table('memberships')->insertOrIgnore([
            'userid' => $user->id,
            'groupid' => $group->id,
            'collection' => Membership::COLLECTION_APPROVED,
            'role' => Membership::ROLE_MEMBER,
            'added' => now(),
        ]);

        return $user;
    }

    private function createEngageMail(string $engagement, string $template = 'missing'): object
    {
        $id = DB::table('engage_mails')->insertGetId([
            'engagement' => $engagement,
            'template' => $template,
            'subject' => "Test {$engagement} subject",
            'text' => "Test {$engagement} text",
            'shown' => 0,
            'action' => 0,
            'rate' => 0,
        ]);

        return DB::table('engage_mails')->where('id', $id)->first();
    }

    public function test_held_member_gets_no_mail_and_no_engage_row(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 1,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        $this->lockdown->press(null, 'test lockdown');

        $result = (new EngageEmailService())->processEngageEmails(false);

        $this->assertSame(0, $result['inactive_sent']);
        Mail::assertNothingSent();

        // No engage row: the RESEND_INTERVAL_DAYS clock must not start against a
        // member who was never actually mailed.
        $this->assertDatabaseMissing('engage', ['userid' => $user->id]);

        $counter = DB::table('lockdown_counters')->where('kind', 'deferred:engage')->first();
        $this->assertNotNull($counter);
        $this->assertSame(1, (int) $counter->count);

        $ack = DB::table('lockdown_acks')->where('loop', 'mail-loops')->first();
        $this->assertNotNull($ack);
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }

    public function test_a_member_who_would_not_be_mailed_anyway_is_not_deferred(): void
    {
        // relevantallowed=0 already excludes this member from an Inactive-type send
        // (force=false) before the lockdown check is ever reached, so nothing here is
        // "deferred" - there was never anything to send.
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 0,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        $this->lockdown->press(null, 'test lockdown');

        $result = (new EngageEmailService())->processEngageEmails(false);

        $this->assertSame(0, $result['inactive_sent']);
        $this->assertSame(0, DB::table('lockdown_counters')->where('kind', 'deferred:engage')->count());
    }

    public function test_lifting_email_sends_normally_on_the_next_run(): void
    {
        $user = $this->createUserWithGroupMembership();
        DB::table('users')->where('id', $user->id)->update([
            'relevantallowed' => 1,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
        $this->createEngageMail(EngageEmailService::ENGAGEMENT_INACTIVE, 'missing');

        $this->lockdown->press(null, 'test lockdown');
        $held = (new EngageEmailService())->processEngageEmails(false);
        $this->assertSame(0, $held['inactive_sent']);

        $this->lockdown->setSurfaces(['email' => false], null);

        $sent = (new EngageEmailService())->processEngageEmails(false);
        $this->assertSame(1, $sent['inactive_sent']);
        $this->assertDatabaseHas('engage', [
            'userid' => $user->id,
            'engagement' => EngageEmailService::ENGAGEMENT_INACTIVE,
        ]);
    }
}
