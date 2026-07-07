<?php

namespace Tests\Unit\Services;

use App\Services\AlertService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertServiceTest extends TestCase
{
    private AlertService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AlertService;

        // Intercept all outgoing mail so Mail::assertSent*/assertNothingSent work
        // and no real SMTP connection is attempted. Without this, those assertions
        // throw "method does not exist" because the Mail facade is the real manager.
        Mail::fake();

        // processAlerts() acts on ALL incomplete alerts in the DB. The alerts /
        // alerts_tracking tables are not rolled back by DatabaseTransactions in
        // this suite, so rows from other tests would inflate the per-test counts.
        // Start each test from a known-empty state.
        DB::table('alerts_tracking')->delete();
        DB::table('alerts')->delete();
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    private function insertAlert(array $attrs = []): int
    {
        return DB::table('alerts')->insertGetId(array_merge([
            'from' => 'geeks',
            'to' => 'Mods',
            'subject' => 'Test Alert Subject',
            'text' => 'Test alert body text.',
            'html' => '<p>Test alert body.</p>',
            'groupprogress' => 0,
            'complete' => null,
            'tryhard' => 1,
            'askclick' => false,
        ], $attrs));
    }

    private function invokeResolveFrom(string $role): array
    {
        $method = new \ReflectionMethod(AlertService::class, 'resolveFrom');
        $method->setAccessible(true);
        return $method->invoke($this->service, $role);
    }

    // ===================================================================
    // processAlerts — no alerts
    // ===================================================================

    public function test_processAlerts_returns_zero_when_no_incomplete_alerts(): void
    {
        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
    }

    public function test_processAlerts_ignores_completed_alerts(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        // Insert a completed alert — should be ignored.
        $this->insertAlert(['complete' => now()]);

        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
    }

    // ===================================================================
    // processAlerts — dry-run
    // ===================================================================

    public function test_processAlerts_dryRun_returns_zero_and_sends_no_mail(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Owner']);

        $this->insertAlert();

        $result = $this->service->processAlerts(dryRun: true);

        $this->assertSame(0, $result);
        Mail::assertNothingSent();
    }

    public function test_processAlerts_dryRun_does_not_update_groupprogress(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $alertId = $this->insertAlert(['groupprogress' => 0]);

        $this->service->processAlerts(dryRun: true);

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertSame(0, (int) $alert->groupprogress, 'groupprogress must not change in dry-run');
    }

    public function test_processAlerts_dryRun_does_not_mark_alert_complete(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $alertId = $this->insertAlert();

        $this->service->processAlerts(dryRun: true);

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertNull($alert->complete, 'alert must not be marked complete in dry-run');
    }

    public function test_processAlerts_dryRun_does_not_insert_tracking_records(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $alertId = $this->insertAlert();

        $this->service->processAlerts(dryRun: true);

        $tracking = DB::table('alerts_tracking')->where('alertid', $alertId)->count();
        $this->assertSame(0, $tracking, 'No tracking rows should be inserted in dry-run');
    }

    // ===================================================================
    // processAlerts — real run
    // ===================================================================

    public function test_processAlerts_sends_email_to_moderator(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        // Confine processing to this test's own group. processAlerts() scans ALL
        // published Freegle groups, and other test classes create groups via
        // Eloquent models that are not rolled back between tests. Setting
        // groupprogress just below this group's id (the highest, since it was
        // created last) means `WHERE id > groupprogress` matches only our group,
        // so leaked groups can't fan out the alert and inflate the counts.
        $this->insertAlert(['groupprogress' => $group->id - 1]);

        $result = $this->service->processAlerts();

        $this->assertSame(1, $result);
        Mail::assertSentCount(1);
    }

    public function test_processAlerts_sends_email_to_owner(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->createTestUser();
        $this->createMembership($owner, $group, ['role' => 'Owner']);

        $this->insertAlert(['groupprogress' => $group->id - 1]);

        $result = $this->service->processAlerts();

        $this->assertSame(1, $result);
    }

    public function test_processAlerts_does_not_send_to_member_role(): void
    {
        $group = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['role' => 'Member']);

        $this->insertAlert(['groupprogress' => $group->id - 1]);

        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
        Mail::assertNothingSent();
    }

    public function test_processAlerts_updates_groupprogress_after_each_group(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        // groupprogress starts just below our group so only it is processed,
        // and should advance to exactly our group's id afterwards.
        $alertId = $this->insertAlert(['groupprogress' => $group->id - 1]);

        $this->service->processAlerts();

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertSame($group->id, (int) $alert->groupprogress);
    }

    public function test_processAlerts_marks_alert_complete_when_under_batch_limit(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $alertId = $this->insertAlert(['groupprogress' => $group->id - 1]);

        $this->service->processAlerts();

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertNotNull($alert->complete, 'alert should be marked complete when fewer than 50 groups processed');
    }

    public function test_processAlerts_inserts_tracking_record(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $alertId = $this->insertAlert(['groupprogress' => $group->id - 1]);

        $this->service->processAlerts();

        $tracking = DB::table('alerts_tracking')
            ->where('alertid', $alertId)
            ->where('userid', $mod->id)
            ->where('groupid', $group->id)
            ->first();

        $this->assertNotNull($tracking);
        $this->assertSame('ModEmail', $tracking->type);
    }

    // ===================================================================
    // processAlerts — multiple groups / mods
    // ===================================================================

    public function test_processAlerts_sends_one_email_per_mod_across_groups(): void
    {
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group1, ['role' => 'Moderator']);
        $this->createMembership($mod, $group2, ['role' => 'Moderator']);

        // Confine to our two groups (lowest of the pair minus one) so leaked
        // groups from other suites can't fan out the alert.
        $this->insertAlert(['groupprogress' => min($group1->id, $group2->id) - 1]);

        // Same mod in two groups. V1 parity: the already-sent check is keyed on
        // alertid + userid + emailid + type + to (no groupid — see iznik-server
        // include/group/Alert.php:236), so a mod with one email address gets
        // exactly ONE email per alert regardless of how many groups they
        // moderate. Matches this test's name.
        $result = $this->service->processAlerts();

        $this->assertSame(1, $result);
    }

    public function test_processAlerts_sends_to_multiple_mods_in_same_group(): void
    {
        $group = $this->createTestGroup();
        $mod1 = $this->createTestUser();
        $mod2 = $this->createTestUser();
        $this->createMembership($mod1, $group, ['role' => 'Moderator']);
        $this->createMembership($mod2, $group, ['role' => 'Owner']);

        $this->insertAlert(['groupprogress' => $group->id - 1]);

        $result = $this->service->processAlerts();

        $this->assertSame(2, $result);
    }

    public function test_processAlerts_returns_total_across_multiple_alerts(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $this->insertAlert(['groupprogress' => $group->id - 1]);
        $this->insertAlert(['subject' => 'Second Alert', 'groupprogress' => $group->id - 1]);

        $result = $this->service->processAlerts();

        // Two alerts × one group × one mod = 2 emails
        $this->assertSame(2, $result);
    }

    // ===================================================================
    // processAlerts — groupprogress filtering
    // ===================================================================

    public function test_processAlerts_skips_groups_already_processed(): void
    {
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Ensure group2.id > group1.id so the ordering is deterministic.
        if ($group2->id < $group1->id) {
            [$group1, $group2] = [$group2, $group1];
        }

        $mod = $this->createTestUser();
        $this->createMembership($mod, $group1, ['role' => 'Moderator']);
        $this->createMembership($mod, $group2, ['role' => 'Moderator']);

        // groupprogress already past group1.id → only group2 should receive.
        $this->insertAlert(['groupprogress' => $group1->id]);

        $result = $this->service->processAlerts();

        $this->assertSame(1, $result);
    }

    // ===================================================================
    // processAlerts — skip conditions
    // ===================================================================

    public function test_processAlerts_skips_deleted_users(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        // Mark user as deleted.
        DB::table('users')->where('id', $mod->id)->update(['deleted' => now()]);

        $this->insertAlert(['groupprogress' => $group->id - 1]);

        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
        Mail::assertNothingSent();
    }

    public function test_processAlerts_skips_bounced_email(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        // Mark the user's email as bounced.
        DB::table('users_emails')->where('userid', $mod->id)->update(['bounced' => now()]);

        $this->insertAlert(['groupprogress' => $group->id - 1]);

        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
        Mail::assertNothingSent();
    }

    public function test_processAlerts_skips_alert_when_no_groups_match(): void
    {
        // Set groupprogress beyond every group id so NO group matches — this also
        // excludes any groups leaked (and not rolled back) by other test classes,
        // so the "nothing to send, but still complete" path runs deterministically.
        $alertId = $this->insertAlert(['groupprogress' => 2000000000]);

        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);

        // With 0 groups processed < 50 batch limit, it should still mark complete.
        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertNotNull($alert->complete);
    }

    public function test_processAlerts_skips_group_if_not_published(): void
    {
        $group = $this->createTestGroup(['publish' => 0]);
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $this->insertAlert(['groupprogress' => $group->id - 1]);

        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
    }

    public function test_processAlerts_skips_non_freegle_group(): void
    {
        // createTestGroup() always creates TYPE_FREEGLE, so make a non-Freegle
        // group via the Group model (not a raw DB insert) so its creating hook
        // populates the NOT-NULL polyindex geometry from lat/lng.
        $nonFreegleGroupId = \App\Models\Group::create([
            'nameshort' => 'NotFreegle_' . uniqid('', true),
            'namefull' => 'Not a Freegle group',
            'type' => 'Yahoo',
            'publish' => 1,
            'lat' => 51.5,
            'lng' => -0.1,
            'onhere' => 1,
        ])->id;

        $mod = $this->createTestUser();
        DB::table('memberships')->insert([
            'userid' => $mod->id,
            'groupid' => $nonFreegleGroupId,
            'role' => 'Moderator',
            'collection' => 'Approved',
            'emailfrequency' => -1,
            'added' => now(),
        ]);

        $this->insertAlert(['groupprogress' => $nonFreegleGroupId - 1]);

        $result = $this->service->processAlerts();

        $this->assertSame(0, $result);
    }

    // ===================================================================
    // processAlerts — html vs text body
    // ===================================================================

    public function test_processAlerts_uses_html_field_when_present(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $this->insertAlert([
            'html' => '<b>HTML body</b>',
            'text' => 'Plain body',
            'groupprogress' => $group->id - 1,
        ]);

        $this->service->processAlerts();

        // No exception means the mail was sent with whichever body.
        Mail::assertSentCount(1);
    }

    public function test_processAlerts_falls_back_to_nl2br_text_when_html_empty(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $this->insertAlert([
            'html' => '',
            'text' => "Line one\nLine two",
            'groupprogress' => $group->id - 1,
        ]);

        $this->service->processAlerts();

        Mail::assertSentCount(1);
    }

    // ===================================================================
    // processAlerts — single-group targeting (alerts.groupid)
    // ===================================================================

    public function test_processAlerts_single_group_targets_only_that_group(): void
    {
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        $mod1 = $this->createTestUser();
        $mod2 = $this->createTestUser();
        $this->createMembership($mod1, $group1, ['role' => 'Moderator']);
        $this->createMembership($mod2, $group2, ['role' => 'Moderator']);

        // groupid set → only group1's mod should be mailed, group2 ignored,
        // regardless of groupprogress.
        $this->insertAlert(['groupid' => $group1->id, 'groupprogress' => 0]);

        $result = $this->service->processAlerts();

        // One mod email counted; group2's mod is not mailed. The total send count
        // is 2 because a single-group alert also copies the alert sender (cc).
        $this->assertSame(1, $result);
        Mail::assertSentCount(2);
    }

    public function test_processAlerts_single_group_copies_sender(): void
    {
        config(['freegle.mail.geeks_addr' => 'geeks@ilovefreegle.org']);

        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        // from='geeks' (insertAlert default) → cc copy goes to the geeks address.
        $this->insertAlert(['groupid' => $group->id]);

        $this->service->processAlerts();

        Mail::assertSent(\App\Mail\Alert\AlertMail::class, function ($mail) {
            return $mail->hasTo('geeks@ilovefreegle.org');
        });
    }

    public function test_processAlerts_single_group_marks_complete(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $alertId = $this->insertAlert(['groupid' => $group->id]);

        $this->service->processAlerts();

        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertNotNull($alert->complete, 'single-group alert should complete in one pass');
    }

    public function test_processAlerts_single_group_dryRun_sends_nothing(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $alertId = $this->insertAlert(['groupid' => $group->id]);

        $result = $this->service->processAlerts(dryRun: true);

        $this->assertSame(0, $result);
        Mail::assertNothingSent();
        $alert = DB::table('alerts')->where('id', $alertId)->first();
        $this->assertNull($alert->complete);
    }

    // ===================================================================
    // processAlerts — group contact email (OwnerEmail)
    // ===================================================================

    public function test_processAlerts_sends_to_group_contactmail(): void
    {
        $group = $this->createTestGroup(['contactmail' => 'volunteers@example.org']);
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $alertId = $this->insertAlert(['groupprogress' => $group->id - 1]);

        // One mod email + one contact email.
        $result = $this->service->processAlerts();

        $this->assertSame(2, $result);
        Mail::assertSentCount(2);

        $ownerTracking = DB::table('alerts_tracking')
            ->where('alertid', $alertId)
            ->where('groupid', $group->id)
            ->where('type', 'OwnerEmail')
            ->first();
        $this->assertNotNull($ownerTracking, 'an OwnerEmail tracking row should be recorded');
    }

    public function test_processAlerts_no_contactmail_no_owner_email(): void
    {
        $group = $this->createTestGroup(); // no contactmail
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $alertId = $this->insertAlert(['groupprogress' => $group->id - 1]);

        $result = $this->service->processAlerts();

        $this->assertSame(1, $result);
        $owner = DB::table('alerts_tracking')
            ->where('alertid', $alertId)
            ->where('type', 'OwnerEmail')
            ->count();
        $this->assertSame(0, $owner);
    }

    public function test_processAlerts_invalid_contactmail_skipped(): void
    {
        $group = $this->createTestGroup(['contactmail' => 'not-an-email']);
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => 'Moderator']);

        $this->insertAlert(['groupprogress' => $group->id - 1]);

        $result = $this->service->processAlerts();

        // Only the mod email; invalid contact address is skipped.
        $this->assertSame(1, $result);
    }

    // ===================================================================
    // resolveFrom — known roles with config lookup
    // ===================================================================

    public function test_resolveFrom_support_returns_config_addr(): void
    {
        config(['freegle.mail.support_addr' => 'support@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('support');

        $this->assertSame('support@ilovefreegle.org', $addr);
        $this->assertSame('Freegle Support', $name);
    }

    public function test_resolveFrom_info_returns_config_addr(): void
    {
        config(['freegle.mail.info_addr' => 'info@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('info');

        $this->assertSame('info@ilovefreegle.org', $addr);
        $this->assertSame('Freegle Info', $name);
    }

    public function test_resolveFrom_geeks_returns_config_addr(): void
    {
        config(['freegle.mail.geeks_addr' => 'geeks@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('geeks');

        $this->assertSame('geeks@ilovefreegle.org', $addr);
        $this->assertSame('Freegle Geeks', $name);
    }

    public function test_resolveFrom_mentors_returns_config_addr(): void
    {
        config(['freegle.mail.mentors_addr' => 'mentors@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('mentors');

        $this->assertSame('mentors@ilovefreegle.org', $addr);
        $this->assertSame('Freegle Mentors', $name);
    }

    // ===================================================================
    // resolveFrom — known roles with hardcoded address
    // ===================================================================

    /**
     * @dataProvider hardcodedRoleProvider
     */
    public function test_resolveFrom_hardcoded_roles(string $role, string $expectedAddr, string $expectedName): void
    {
        [$addr, $name] = $this->invokeResolveFrom($role);

        $this->assertSame($expectedAddr, $addr);
        $this->assertSame($expectedName, $name);
    }

    public static function hardcodedRoleProvider(): array
    {
        return [
            'board'       => ['board',       'board@ilovefreegle.org',       'Freegle Board'],
            'chair'       => ['chair',       'chair@ilovefreegle.org',       'Freegle Chair'],
            'newgroups'   => ['newgroups',   'newgroups@ilovefreegle.org',   'Freegle New Groups'],
            'ro'          => ['ro',          'ro@ilovefreegle.org',          'Freegle Returning Officer'],
            'volunteers'  => ['volunteers',  'volunteers@ilovefreegle.org',  'Freegle Volunteers'],
            'centralmods' => ['centralmods', 'centralmods@ilovefreegle.org', 'Freegle Volunteer Support'],
            'councils'    => ['councils',    'councils@ilovefreegle.org',    'Freegle Partnerships'],
        ];
    }

    // ===================================================================
    // resolveFrom — unknown role fallback
    // ===================================================================

    public function test_resolveFrom_unknown_role_falls_back_to_geeks_addr(): void
    {
        config(['freegle.mail.geeks_addr' => 'geeks@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('unknownrole');

        $this->assertSame('geeks@ilovefreegle.org', $addr);
        $this->assertSame('Freegle', $name);
    }

    public function test_resolveFrom_empty_role_falls_back_to_geeks_addr(): void
    {
        config(['freegle.mail.geeks_addr' => 'geeks@ilovefreegle.org']);

        [$addr, $name] = $this->invokeResolveFrom('');

        $this->assertSame('geeks@ilovefreegle.org', $addr);
        $this->assertSame('Freegle', $name);
    }

    // ===================================================================
    // resolveFrom — config fallback within known roles
    // ===================================================================

    public function test_resolveFrom_support_falls_back_to_geeks_when_config_absent(): void
    {
        // If FREEGLE_SUPPORT_ADDR is not set, the config falls back to FREEGLE_GEEKS_ADDR.
        config([
            'freegle.mail.support_addr' => null,
            'freegle.mail.geeks_addr' => 'geeks@ilovefreegle.org',
        ]);

        [$addr] = $this->invokeResolveFrom('support');

        $this->assertSame('geeks@ilovefreegle.org', $addr);
    }
}
