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
            'freegle.reengage.trigger_days' => 30,
            'freegle.reengage.max_days' => 175,
            // Small gap keeps the progression tests' timings simple; production
            // graduates wider (see config/freegle.php).
            'freegle.reengage.stage_gap_days' => 10,
        ]);
    }

    private function createFreegleGroup(): Group
    {
        return Group::create([
            'nameshort' => 'TestReng_' . uniqid(),
            'type'      => Group::TYPE_FREEGLE,
            'publish'   => 1,
            'onmap'     => 1,
            'onhere'    => 1,
            'lat'       => 51.5074,
            'lng'       => -0.1278,
        ]);
    }

    /**
     * A lapsed user: member of a Freegle group, last seen $daysInactive ago.
     */
    private function createLapsedUser(int $daysInactive = 100, array $attrs = []): object
    {
        $user = $this->createTestUser($attrs);
        $group = $this->createFreegleGroup();
        DB::table('memberships')->insertOrIgnore([
            'userid'     => $user->id,
            'groupid'    => $group->id,
            'collection' => Membership::COLLECTION_APPROVED,
            'role'       => Membership::ROLE_MEMBER,
            'added'      => now(),
        ]);
        DB::table('users')->where('id', $user->id)->update(['lastaccess' => now()->subDays($daysInactive)]);

        return $user->fresh();
    }

    private function insertSend(int $userId, int $stage, string $template, $sentat): void
    {
        DB::table('reengage')->insert([
            'userid'   => $userId,
            'stage'    => $stage,
            'template' => $template,
            'sentat'   => $sentat,
        ]);
    }

    // ── Dark-ship gates ──────────────────────────────────────────────────────

    public function test_dark_when_allowlist_empty(): void
    {
        $this->createLapsedUser(100);
        $this->enable('');                          // type enabled, but nobody allow-listed
        config(['freegle.mail.enabled_types' => 'Reengage']);

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['stage1']);
        Mail::assertNothingSent();
    }

    public function test_dark_when_type_not_enabled(): void
    {
        $this->createLapsedUser(100);
        config(['freegle.reengage.allowlist' => '*', 'freegle.mail.enabled_types' => '']);

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['stage1']);
    }

    public function test_skips_user_not_on_allowlist(): void
    {
        $this->createLapsedUser(100);
        $this->enable('someoneelse@example.com');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['stage1']);
    }

    // ── Stage 1 ──────────────────────────────────────────────────────────────

    public function test_sends_stage1_to_lapsed_user(): void
    {
        $user = $this->createLapsedUser(100);
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(1, $result['stage1']);
        $this->assertDatabaseHas('reengage', ['userid' => $user->id, 'stage' => 1, 'template' => 'nearby']);

        // The send is spooled, not Mail::send-ed directly — flush + assert.
        $stats = app(EmailSpoolerService::class)->processSpool();
        $this->assertSame(1, $stats['sent']);
    }

    public function test_does_not_send_to_recently_active_user(): void
    {
        $this->createLapsedUser(14);          // inside the trigger window — not lapsed yet
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['stage1']);
    }

    public function test_does_not_send_to_user_past_max_inactivity(): void
    {
        $this->createLapsedUser(200);         // beyond max_days — engage sunset owns this
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['stage1']);
    }

    public function test_skips_user_without_freegle_membership(): void
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update(['lastaccess' => now()->subDays(100)]);
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['stage1']);
    }

    public function test_skips_user_on_holiday(): void
    {
        $user = $this->createLapsedUser(100);
        DB::table('users')->where('id', $user->id)->update(['onholidaytill' => now()->addDays(5)]);
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['stage1']);
    }

    // ── Stage progression ────────────────────────────────────────────────────

    public function test_respects_stage_gap(): void
    {
        $user = $this->createLapsedUser(100);
        $this->enable('*');
        $this->insertSend($user->id, 1, 'nearby', now());   // just sent stage 1

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(0, $result['stage2']);
    }

    public function test_advances_to_stage2_after_gap(): void
    {
        $user = $this->createLapsedUser(100);
        $this->enable('*');
        $this->insertSend($user->id, 1, 'nearby', now()->subDays(11));

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(1, $result['stage2']);
        $this->assertDatabaseHas('reengage', ['userid' => $user->id, 'stage' => 2, 'template' => 'impact']);
    }

    public function test_advances_to_stage3_after_two_sends(): void
    {
        $user = $this->createLapsedUser(100);
        $this->enable('*');
        $this->insertSend($user->id, 1, 'nearby', now()->subDays(22));
        $this->insertSend($user->id, 2, 'impact', now()->subDays(11));

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(1, $result['stage3']);
        $this->assertDatabaseHas('reengage', ['userid' => $user->id, 'stage' => 3, 'template' => 'preferences']);
    }

    public function test_suppresses_after_full_sequence(): void
    {
        $user = $this->createLapsedUser(100);
        $this->enable('*');
        $this->insertSend($user->id, 1, 'nearby', now()->subDays(33));
        $this->insertSend($user->id, 2, 'impact', now()->subDays(22));
        $this->insertSend($user->id, 3, 'preferences', now()->subDays(11));

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(1, $result['suppressed']);
        $this->assertSame(0, $result['stage1'] + $result['stage2'] + $result['stage3']);
        $this->assertDatabaseHas('reengage', ['userid' => $user->id, 'stage' => 3, 'outcome' => 'Suppressed']);
    }

    public function test_old_sequence_before_lastaccess_is_ignored(): void
    {
        // User re-engaged after a previous sequence (sends older than their
        // lastaccess), then lapsed again. The old sends must not block a fresh
        // stage-1 send.
        $user = $this->createLapsedUser(100);
        $this->enable('*');
        $this->insertSend($user->id, 1, 'nearby', now()->subDays(160));
        $this->insertSend($user->id, 2, 'impact', now()->subDays(150));

        $result = (new ReengageService())->processReengageEmails(false);

        $this->assertSame(1, $result['stage1']);
    }

    // ── Dry run ──────────────────────────────────────────────────────────────

    public function test_dry_run_does_not_send_or_record(): void
    {
        $user = $this->createLapsedUser(100);
        $this->enable('*');

        $result = (new ReengageService())->processReengageEmails(true);

        $this->assertSame(1, $result['stage1']);   // counted...
        $this->assertDatabaseMissing('reengage', ['userid' => $user->id]); // ...but not recorded
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
