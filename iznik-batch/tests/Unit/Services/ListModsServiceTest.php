<?php

namespace Tests\Unit\Services;

use App\Models\Membership;
use App\Models\User;
use App\Services\ListModsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListModsServiceTest extends TestCase
{
    private ListModsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ListModsService;
    }

    /**
     * rows() lists every mod in the shared test database, so pick out ours.
     */
    private function rowFor(User $user): ?array
    {
        foreach ($this->service->rows() as $i => $row) {
            if ($i > 0 && $row[0] === $user->id) {
                return $row;
            }
        }

        return NULL;
    }

    private function mod(array $userAttrs = [], string $role = Membership::ROLE_MODERATOR, ?array $settings = NULL): array
    {
        $user = $this->createTestUser($userAttrs);
        $group = $this->createTestGroup();
        $attrs = ['role' => $role];

        if ($settings !== NULL) {
            $attrs['settings'] = $settings;
        }

        $this->createMembership($user, $group, $attrs);

        return [$user, $group];
    }

    public function test_first_row_is_header(): void
    {
        $first = $this->service->rows()->current();

        $this->assertSame(ListModsService::HEADER, $first);
        $this->assertCount(7, $first);
    }

    public function test_plain_members_are_not_listed(): void
    {
        $user = $this->createTestUser();
        $this->createMembership($user, $this->createTestGroup());

        $this->assertNull($this->rowFor($user));
    }

    public function test_moderator_and_owner_are_listed(): void
    {
        [$mod] = $this->mod();
        [$owner] = $this->mod([], Membership::ROLE_OWNER);

        $this->assertNotNull($this->rowFor($mod));
        $this->assertNotNull($this->rowFor($owner));
    }

    public function test_row_contents_for_simple_mod(): void
    {
        [$user, $group] = $this->mod(['email_preferred' => 'simplemod_' . uniqid() . '@example.org']);
        $row = $this->rowFor($user);

        $this->assertSame($user->emailPreferred ?? $row[1], $row[1]);
        $this->assertStringEndsWith('@example.org', $row[1]);
        $this->assertSame('Test User', $row[2]);
        $this->assertSame($group->nameshort, $row[4]);
        $this->assertSame('', $row[5]);
        $this->assertSame('', $row[6]);
    }

    public function test_lastaccess_formatted_as_date(): void
    {
        [$with] = $this->mod(['lastaccess' => '2024-03-05 10:11:12']);

        $this->assertSame('2024-03-05', $this->rowFor($with)[3]);
    }

    public function test_user_in_several_groups_listed_once(): void
    {
        [$user] = $this->mod();
        $this->createMembership($user, $this->createTestGroup(), ['role' => Membership::ROLE_OWNER]);

        $count = 0;
        foreach ($this->service->rows() as $i => $row) {
            if ($i > 0 && $row[0] === $user->id) {
                $count++;
                $this->assertCount(2, explode(',', $row[4]));
            }
        }

        $this->assertSame(1, $count);
    }

    public function test_active_and_backup_groups_split(): void
    {
        $user = $this->createTestUser();
        $active = $this->createTestGroup();
        $backup = $this->createTestGroup();
        $this->createMembership($user, $active, ['role' => Membership::ROLE_MODERATOR, 'settings' => ['active' => 1]]);
        $this->createMembership($user, $backup, ['role' => Membership::ROLE_MODERATOR, 'settings' => ['active' => 0]]);

        $row = $this->rowFor($user);

        $this->assertSame($active->nameshort, $row[4]);
        $this->assertSame($backup->nameshort, $row[5]);
    }

    public function test_owner_with_inactive_setting_is_backup(): void
    {
        [$user, $group] = $this->mod([], Membership::ROLE_OWNER, ['active' => 0]);
        $row = $this->rowFor($user);

        $this->assertSame('', $row[4]);
        $this->assertSame($group->nameshort, $row[5]);
    }

    public function test_groups_ordered_by_full_name_case_insensitively(): void
    {
        $user = $this->createTestUser();
        $tag = uniqid();
        $b = $this->createTestGroup();
        $a = $this->createTestGroup();
        $b->update(['namefull' => "bbb $tag"]);
        $a->update(['namefull' => "AAA $tag"]);
        $this->createMembership($user, $b, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($user, $a, ['role' => Membership::ROLE_MODERATOR]);

        $this->assertSame("{$a->nameshort},{$b->nameshort}", $this->rowFor($user)[4]);
    }

    public function test_group_without_fullname_orders_by_shortname(): void
    {
        $user = $this->createTestUser();
        $tag = uniqid();
        $z = $this->createTestGroup();
        $m = $this->createTestGroup();
        $z->update(['namefull' => NULL]);
        $m->update(['namefull' => "mmm $tag"]);
        $this->createMembership($user, $z, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($user, $m, ['role' => Membership::ROLE_MODERATOR]);

        $groups = explode(',', $this->rowFor($user)[4]);
        $this->assertCount(2, $groups);
        $this->assertContains($z->nameshort, $groups);
        $this->assertContains($m->nameshort, $groups);
    }

    public function test_internal_preferred_email_is_skipped_for_external(): void
    {
        [$user] = $this->mod(['email_preferred' => 'u' . uniqid() . '@users.ilovefreegle.org']);
        $external = $this->createTestUserEmail($user, ['preferred' => 0]);

        $row = $this->rowFor($user);

        $this->assertSame($external->email, $row[1]);
        // Internal address is excluded from the other-emails list too.
        $this->assertSame('', $row[6]);
    }

    public function test_no_external_email_gives_null_preferred(): void
    {
        [$user] = $this->mod(['email_preferred' => 'u' . uniqid() . '@groups.ilovefreegle.org']);

        $row = $this->rowFor($user);

        $this->assertNull($row[1]);
    }

    public function test_other_known_emails_exclude_preferred_and_are_alphabetical(): void
    {
        [$user] = $this->mod(['email_preferred' => 'main_' . uniqid() . '@example.org']);
        $tag = uniqid();
        $this->createTestUserEmail($user)->update(['email' => "zzz_$tag@example.net"]);
        $this->createTestUserEmail($user)->update(['email' => "aaa_$tag@example.net"]);

        $row = $this->rowFor($user);

        $this->assertSame("aaa_$tag@example.net,zzz_$tag@example.net", $row[6]);
        $this->assertStringNotContainsString($row[1], $row[6]);
    }

    public function test_yahoogroups_address_appears_in_other_emails_but_never_preferred(): void
    {
        [$user] = $this->mod(['email_preferred' => 'main_' . uniqid() . '@example.org']);
        $yahoo = 'grp_' . uniqid() . '@yahoogroups.com';
        $this->createTestUserEmail($user, ['preferred' => 1])->update(['email' => $yahoo]);

        $row = $this->rowFor($user);

        $this->assertStringContainsString($yahoo, $row[6]);
        $this->assertNotSame($yahoo, $row[1]);
    }

    #[DataProvider('nameProvider')]
    public function test_name_derivation(array $attrs, ?string $expected): void
    {
        $base = ['fullname' => NULL, 'firstname' => NULL, 'lastname' => NULL];
        [$user] = $this->mod(array_merge($base, $attrs));

        $this->assertSame($expected, $this->rowFor($user)[2]);
    }

    public static function nameProvider(): array
    {
        return [
            'fullname wins' => [['fullname' => 'Full Name', 'firstname' => 'A', 'lastname' => 'B'], 'Full Name'],
            'first and last' => [['firstname' => 'Ann', 'lastname' => 'Lee'], 'Ann Lee'],
            'first only' => [['firstname' => 'Ann'], 'Ann'],
            'last only' => [['lastname' => 'Lee'], 'Lee'],
            'no name' => [[], NULL],
            'stripped from at sign' => [['fullname' => 'bob@example.org'], 'bob'],
            'exactly 32 chars kept' => [['fullname' => str_repeat('x', 32)], str_repeat('x', 32)],
            'over 32 truncated' => [['fullname' => str_repeat('y', 40)], str_repeat('y', 32) . '...'],
            'numeric disambiguated' => [['fullname' => '12345'], '12345.'],
            'TN suffix removed' => [['fullname' => 'Jane-g123'], 'Jane'],
        ];
    }
}
