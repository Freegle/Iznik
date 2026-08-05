<?php

namespace Tests\Feature\User;

use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use App\Models\UserEmail;
use Tests\TestCase;

/**
 * Tests for user:list-mods, the port of V1 scripts/fix/fix_listmods.php.
 *
 * The command emits a CSV of every user who is a Moderator or Owner of any
 * group: userid, preferred email, name, last access date, active-mod groups,
 * backup-mod groups, and other known (non-internal) emails.
 */
class ListModsCommandTest extends TestCase
{
    private const HEADER = [
        'userid',
        'email',
        'name',
        'lastaccess',
        'activemod',
        'backupmod',
        'other known emails',
    ];

    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputPath = tempnam(sys_get_temp_dir(), 'listmods');
    }

    protected function tearDown(): void
    {
        @unlink($this->outputPath);
        parent::tearDown();
    }

    /**
     * Run the command and parse the CSV it wrote into rows keyed by userid.
     *
     * @return array{header: array, rows: array<int, array>}
     */
    private function runAndParse(): array
    {
        $this->artisan('user:list-mods', ['--output' => $this->outputPath])
            ->assertExitCode(0);

        $lines = file($this->outputPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotEmpty($lines, 'CSV output should at least contain a header row');

        $header = str_getcsv(array_shift($lines));
        $rows = [];

        foreach ($lines as $line) {
            $fields = str_getcsv($line);
            $this->assertCount(count(self::HEADER), $fields);
            $rows[(int) $fields[0]] = array_combine(self::HEADER, $fields);
        }

        return ['header' => $header, 'rows' => $rows];
    }

    /** Create a bare user (no email) so email tests control users_emails exactly. */
    private function createBareUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'firstname' => NULL,
            'lastname' => NULL,
            'fullname' => 'Bare User',
            'added' => now(),
        ], $attributes));
    }

    private function addEmail(User $user, string $email, int $preferred = 0): UserEmail
    {
        return UserEmail::create([
            'userid' => $user->id,
            'email' => $email,
            'preferred' => $preferred,
            'added' => now(),
        ]);
    }

    public function test_outputs_header_and_exactly_one_row_per_existing_mod(): void
    {
        // The suite shares one test DB and some earlier tests commit
        // moderators that DatabaseTransactions cannot roll back, so an empty
        // DB cannot be assumed: assert the command emits the header plus
        // exactly one row per distinct mod/owner userid present at run time.
        $expected = Membership::query()
            ->whereIn('role', [Membership::ROLE_MODERATOR, Membership::ROLE_OWNER])
            ->distinct()
            ->pluck('userid')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $parsed = $this->runAndParse();

        $this->assertSame(self::HEADER, $parsed['header']);

        $actual = array_keys($parsed['rows']);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_lists_moderators_and_owners_but_not_members(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $owner = $this->createTestUser();
        $member = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($owner, $group, ['role' => Membership::ROLE_OWNER]);
        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        $rows = $this->runAndParse()['rows'];

        $this->assertArrayHasKey($mod->id, $rows);
        $this->assertArrayHasKey($owner->id, $rows);
        $this->assertArrayNotHasKey($member->id, $rows);
    }

    public function test_moderator_of_two_groups_appears_once_with_groups_sorted_by_name(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $groupA, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($mod, $groupB, ['role' => Membership::ROLE_OWNER]);

        $rows = $this->runAndParse()['rows'];

        $this->assertArrayHasKey($mod->id, $rows);

        // V1 ordered memberships by LOWER(namefull ?? nameshort) but emitted nameshort.
        $expected = [$groupA, $groupB];
        usort($expected, fn ($a, $b) => strcmp(
            strtolower($a->namefull ?? $a->nameshort),
            strtolower($b->namefull ?? $b->nameshort)
        ));
        $expected = implode(',', array_map(fn ($g) => $g->nameshort, $expected));

        $this->assertSame($expected, $rows[$mod->id]['activemod']);
        $this->assertSame('', $rows[$mod->id]['backupmod']);
    }

    public function test_backup_mod_split_uses_settings_active_and_legacy_showmessages(): void
    {
        $mod = $this->createTestUser();
        $noSettings = $this->createTestGroup();
        $activeFalse = $this->createTestGroup();
        $showmessagesOff = $this->createTestGroup();
        $activeWinsOverShowmessages = $this->createTestGroup();

        $this->createMembership($mod, $noSettings, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($mod, $activeFalse, [
            'role' => Membership::ROLE_MODERATOR,
            'settings' => ['active' => FALSE],
        ]);
        $this->createMembership($mod, $showmessagesOff, [
            'role' => Membership::ROLE_MODERATOR,
            'settings' => ['showmessages' => 0],
        ]);
        $this->createMembership($mod, $activeWinsOverShowmessages, [
            'role' => Membership::ROLE_MODERATOR,
            'settings' => ['active' => 1, 'showmessages' => 0],
        ]);

        $row = $this->runAndParse()['rows'][$mod->id];

        $active = explode(',', $row['activemod']);
        $backup = explode(',', $row['backupmod']);

        $this->assertContains($noSettings->nameshort, $active);
        $this->assertContains($activeWinsOverShowmessages->nameshort, $active);
        $this->assertContains($activeFalse->nameshort, $backup);
        $this->assertContains($showmessagesOff->nameshort, $backup);
    }

    public function test_preferred_email_skips_internal_domains_and_orders_alphabetically(): void
    {
        $group = $this->createTestGroup();

        // Internal domain wins the preferred flag but must be skipped.
        $internalPreferred = $this->createBareUser();
        $this->addEmail($internalPreferred, 'mod123@users.ilovefreegle.org', preferred: 1);
        $this->addEmail($internalPreferred, 'real@example.com');
        $this->createMembership($internalPreferred, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Two candidates with equal preferred flag: email ASC decides, not
        // insertion order.  users_emails.email is unique, so per-user addresses.
        $alphabetical = $this->createBareUser();
        $this->addEmail($alphabetical, 'zzz.alpha@example.com');
        $this->addEmail($alphabetical, 'aaa.alpha@example.com');
        $this->createMembership($alphabetical, $group, ['role' => Membership::ROLE_MODERATOR]);

        // The preferred flag beats alphabetical order.
        $flagged = $this->createBareUser();
        $this->addEmail($flagged, 'aaa.flag@example.com');
        $this->addEmail($flagged, 'zzz.flag@example.com', preferred: 1);
        $this->createMembership($flagged, $group, ['role' => Membership::ROLE_MODERATOR]);

        $rows = $this->runAndParse()['rows'];

        $this->assertSame('real@example.com', $rows[$internalPreferred->id]['email']);
        $this->assertSame('aaa.alpha@example.com', $rows[$alphabetical->id]['email']);
        $this->assertSame('zzz.flag@example.com', $rows[$flagged->id]['email']);
    }

    public function test_other_emails_excludes_preferred_and_internal_but_keeps_yahoogroups(): void
    {
        // V1 used two different filters: the preferred email skips internal
        // domains AND @yahoogroups., but "other known emails" only skips
        // internal domains - so a yahoogroups address shows up there.
        $group = $this->createTestGroup();
        $mod = $this->createBareUser();
        $this->addEmail($mod, 'main@example.com', preferred: 1);
        $this->addEmail($mod, 'second@example.com');
        $this->addEmail($mod, 'mod123@users.ilovefreegle.org');
        $this->addEmail($mod, 'old@yahoogroups.com');
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $row = $this->runAndParse()['rows'][$mod->id];

        $this->assertSame('main@example.com', $row['email']);
        $others = explode(',', $row['other known emails']);
        $this->assertContains('second@example.com', $others);
        $this->assertContains('old@yahoogroups.com', $others);
        $this->assertNotContains('mod123@users.ilovefreegle.org', $others);
        $this->assertNotContains('main@example.com', $others);
    }

    public function test_user_with_no_emails_has_blank_email_columns(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createBareUser();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $row = $this->runAndParse()['rows'][$mod->id];

        $this->assertSame('', $row['email']);
        $this->assertSame('', $row['other known emails']);
    }

    public function test_lastaccess_formatted_as_date(): void
    {
        // users.lastaccess is NOT NULL DEFAULT CURRENT_TIMESTAMP, so the
        // blank case in V1's null-guard is unreachable; only the date
        // formatting is observable.
        $group = $this->createTestGroup();

        $active = $this->createTestUser(['lastaccess' => '2026-07-01 12:34:56']);
        $this->createMembership($active, $group, ['role' => Membership::ROLE_MODERATOR]);

        $rows = $this->runAndParse()['rows'];

        $this->assertSame('2026-07-01', $rows[$active->id]['lastaccess']);
    }

    public function test_name_transformations_match_v1_getname(): void
    {
        $group = $this->createTestGroup();

        $cases = [
            // [user attributes, expected name]
            [['fullname' => 'Bob The Builder-g123'], 'Bob The Builder'],
            [['fullname' => NULL, 'firstname' => 'Jane', 'lastname' => NULL], 'Jane'],
            [['fullname' => NULL, 'firstname' => 'Jane', 'lastname' => 'Doe'], 'Jane Doe'],
            [['fullname' => 'someone@example.com'], 'someone'],
            [['fullname' => str_repeat('x', 40)], str_repeat('x', 32) . '...'],
            [['fullname' => '12345'], '12345.'],
        ];

        $users = [];
        foreach ($cases as [$attributes, $expected]) {
            $user = $this->createBareUser($attributes);
            $this->createMembership($user, $group, ['role' => Membership::ROLE_MODERATOR]);
            $users[] = [$user, $expected];
        }

        $rows = $this->runAndParse()['rows'];

        foreach ($users as [$user, $expected]) {
            $this->assertSame($expected, $rows[$user->id]['name'], "user {$user->id}");
        }
    }

    public function test_includes_unpublished_groups(): void
    {
        // V1 CLI scripts ran with Session::modtools() defaulting TRUE, so the
        // memberships query did NOT filter on groups.publish.
        $group = $this->createTestGroup(['publish' => 0]);
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $rows = $this->runAndParse()['rows'];

        $this->assertArrayHasKey($mod->id, $rows);
        $this->assertSame($group->nameshort, $rows[$mod->id]['activemod']);
    }

    public function test_writes_csv_to_stdout_without_output_option(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        // fputcsv to php://stdout bypasses the console output buffer, so just
        // assert the command succeeds when no --output is given.
        $this->artisan('user:list-mods')->assertExitCode(0);
    }
}
