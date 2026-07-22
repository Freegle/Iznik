<?php

namespace Tests\Feature\Mail;

use App\Models\Group;
use App\Models\Membership;
use App\Services\EmailSpoolerService;
use App\Services\ReengageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * First-week onboarding tip sequence (kept under the "reengage" name).
 */
class ReengageEmailsCommandTest extends TestCase
{
    use IsolatedSpoolDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function enable(string $allowlist = '*'): void
    {
        config([
            'freegle.reengage.allowlist' => $allowlist,
            'freegle.mail.enabled_types' => 'Reengage',
            'freegle.reengage.start_day' => 1,
            'freegle.reengage.stage_gap_days' => 1,
            'freegle.reengage.max_start_days' => 7,
        ]);
    }

    private function createFreegleGroup(): Group
    {
        return Group::create([
            'nameshort' => 'TestOnb_' . uniqid(),
            'type'      => Group::TYPE_FREEGLE,
            'publish'   => 1,
            'onmap'     => 1,
            'onhere'    => 1,
            'lat'       => 51.5074,
            'lng'       => -0.1278,
        ]);
    }

    /**
     * A new member: joined a Freegle group, account created $ageDays ago, active.
     */
    private function createNewMember(int $ageDays = 2, array $attrs = []): object
    {
        $user = $this->createTestUser($attrs);
        $group = $this->createFreegleGroup();
        DB::table('memberships')->insertOrIgnore([
            'userid'     => $user->id,
            'groupid'    => $group->id,
            'collection' => Membership::COLLECTION_APPROVED,
            'role'       => Membership::ROLE_MEMBER,
            'added'      => now()->subDays($ageDays),
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'added'           => now()->subDays($ageDays),
            'lastaccess'      => now(),
            'relevantallowed' => 1,
        ]);

        return $user->fresh();
    }

    private function insertTip(int $userId, int $stage, $sentat): void
    {
        DB::table('reengage')->insert([
            'userid'   => $userId,
            'stage'    => $stage,
            'template' => 'tip',
            'sentat'   => $sentat,
        ]);
    }

    // ── Dark-ship gates ──────────────────────────────────────────────────────

    public function test_dark_when_allowlist_empty(): void
    {
        $this->createNewMember(2);
        $this->enable('');
        config(['freegle.mail.enabled_types' => 'Reengage']);

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_dark_when_type_not_enabled(): void
    {
        $this->createNewMember(2);
        config(['freegle.reengage.allowlist' => '*', 'freegle.mail.enabled_types' => '']);

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['sent']);
    }

    public function test_skips_user_not_on_allowlist(): void
    {
        $this->createNewMember(2);
        $this->enable('someoneelse@example.com');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['sent']);
    }

    // ── Tip 1 ────────────────────────────────────────────────────────────────

    public function test_sends_first_tip_to_new_member(): void
    {
        $user = $this->createNewMember(2);
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(1, $result['sent']);
        $this->assertDatabaseHas('reengage', ['userid' => $user->id, 'stage' => 1, 'template' => 'tip']);

        $stats = app(EmailSpoolerService::class)->processSpool();
        $this->assertSame(1, $stats['sent']);
    }

    public function test_send_persists_the_home_sign_off_resolution(): void
    {
        // A new member located inside their group's catchment, with an eligible
        // volunteer, should get a sent row that records how the sign-off was
        // resolved - proving the ReengageService write path, not just the
        // resolveVolunteer query (covered separately).
        $group = $this->createFreegleGroup();
        DB::statement(
            'UPDATE `groups` SET polyindex = ST_GeomFromText(?, 3857) WHERE id = ?',
            ['POLYGON((-0.25 51.40, 0.00 51.40, 0.00 51.60, -0.25 51.60, -0.25 51.40))', $group->id]
        );

        // Eligible sign-off volunteer for the group.
        $mod = $this->createTestUser(['firstname' => 'Mod', 'fullname' => 'Mod', 'lastaccess' => now()]);
        DB::table('memberships')->insert([
            'userid' => $mod->id, 'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR, 'collection' => Membership::COLLECTION_APPROVED,
            'added' => now(),
        ]);

        // New member inside the catchment.
        $user = $this->createTestUser();
        DB::table('memberships')->insert([
            'userid' => $user->id, 'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER, 'collection' => Membership::COLLECTION_APPROVED,
            'rippled' => 0, 'added' => now()->subDays(2),
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'added' => now()->subDays(2),
            'lastaccess' => now(),
            'relevantallowed' => 1,
            'settings' => json_encode(['mylocation' => ['lat' => 51.51, 'lng' => -0.13]]),
        ]);

        $this->enable('*');

        (new ReengageService())->processReengageEmails(false);

        $this->assertDatabaseHas('reengage', [
            'userid' => $user->id,
            'stage' => 1,
            'volunteer_source' => 'home',
            'volunteer_groupid' => $group->id,
        ]);
    }

    public function test_does_not_send_to_account_too_young(): void
    {
        $this->createNewMember(0);      // joined today — welcome mail's day
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['sent']);
    }

    public function test_does_not_start_sequence_for_account_too_old(): void
    {
        $this->createNewMember(10);     // older than max_start_days, no prior tips
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['sent']);
    }

    public function test_excludes_trashnothing_member(): void
    {
        $user = $this->createNewMember(2);
        DB::table('users')->where('id', $user->id)->update(['tnuserid' => 987654]);
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['sent']);
    }

    public function test_excludes_lovejunk_member(): void
    {
        $user = $this->createNewMember(2);
        DB::table('users')->where('id', $user->id)->update(['ljuserid' => 987655]);
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['sent']);
    }

    public function test_skips_user_without_freegle_membership(): void
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update([
            'added' => now()->subDays(2), 'lastaccess' => now(), 'relevantallowed' => 1,
        ]);
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['sent']);
    }

    public function test_skips_user_on_holiday(): void
    {
        $user = $this->createNewMember(2);
        DB::table('users')->where('id', $user->id)->update(['onholidaytill' => now()->addDays(5)]);
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['sent']);
    }

    // ── Daily cadence ─────────────────────────────────────────────────────────

    public function test_does_not_send_two_tips_same_day(): void
    {
        $user = $this->createNewMember(3);
        $this->enable('*');
        $this->insertTip($user->id, 1, now());          // tip 1 already sent today

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['sent']);
    }

    public function test_advances_to_next_tip_the_following_day(): void
    {
        $user = $this->createNewMember(3);
        $this->enable('*');
        $this->insertTip($user->id, 1, now()->subHours(25));  // tip 1 sent > a day ago

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(1, $result['sent']);
        $this->assertDatabaseHas('reengage', ['userid' => $user->id, 'stage' => 2]);
    }

    public function test_completes_after_five_tips(): void
    {
        $user = $this->createNewMember(7);
        $this->enable('*');
        for ($s = 1; $s <= 5; $s++) {
            $this->insertTip($user->id, $s, now()->subDays(6 - $s));
        }

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(1, $result['completed']);
        $this->assertSame(0, $result['sent']);
        $this->assertDatabaseHas('reengage', ['userid' => $user->id, 'stage' => 5, 'outcome' => 'Suppressed']);
    }

    // ── Dry run ──────────────────────────────────────────────────────────────

    public function test_dry_run_does_not_send_or_record(): void
    {
        $user = $this->createNewMember(2);
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(true);

        $this->assertSame(1, $result['sent']);
        $this->assertDatabaseMissing('reengage', ['userid' => $user->id]);
        Mail::assertNothingSent();
    }

    // ── Command smoke ────────────────────────────────────────────────────────

    public function test_command_runs(): void
    {
        $this->artisan('mail:reengage')->assertExitCode(0);
    }

    public function test_command_dry_run_shows_prefix(): void
    {
        $this->artisan('mail:reengage', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);
    }
}
