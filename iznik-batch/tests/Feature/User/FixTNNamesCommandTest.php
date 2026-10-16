<?php

namespace Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FixTNNamesCommandTest extends TestCase
{
    private function createTNUser(string $namePart, ?string $groupId = '12345'): int
    {
        $email = $groupId === null
            ? "{$namePart}@user.trashnothing.com"
            : "{$namePart}-g{$groupId}@user.trashnothing.com";

        return $this->createUserWithEmail($email);
    }

    private function createUserWithEmail(string $email): int
    {
        $userId = DB::table('users')->insertGetId([
            'firstname' => null,
            'lastname'  => null,
            'fullname'  => null,
            'added'     => now(),
        ]);

        DB::table('users_emails')->insert([
            'userid'    => $userId,
            'email'     => $email,
            'backwards' => strrev($email),
            'preferred' => 1,
            'added'     => now(),
        ]);

        return $userId;
    }

    private function createRegularUser(): int
    {
        $userId = DB::table('users')->insertGetId([
            'firstname' => null,
            'lastname'  => null,
            'fullname'  => null,
            'added'     => now(),
        ]);

        $email = 'user-' . uniqid() . '@example.com';

        DB::table('users_emails')->insert([
            'userid'    => $userId,
            'email'     => $email,
            'backwards' => strrev($email),
            'preferred' => 1,
            'added'     => now(),
        ]);

        return $userId;
    }

    /**
     * The shape the old filter could not see. It narrowed on a reversed prefix of
     * backwards, which only matches rows whose backwards came from the address. Most
     * Trash Nothing rows hold REVERSE(canon) instead, with the -gNNNN suffix and the
     * domain dots gone, so the command reached 20.5% of members and skipped the rest
     * without saying so. See .claude/rules/mail-and-data.md.
     */
    public function test_fixes_a_member_whose_row_holds_the_canon_form(): void
    {
        $userId = DB::table('users')->insertGetId([
            'firstname' => null,
            'lastname' => null,
            'fullname' => null,
            'added' => now(),
        ]);

        $email = 'canonform-g4707@user.trashnothing.com';
        $canon = 'canonform@usertrashnothingcom';

        DB::table('users_emails')->insert([
            'userid' => $userId,
            'email' => $email,
            'canon' => $canon,
            'backwards' => strrev($canon),
            'preferred' => 1,
            'added' => now(),
        ]);

        $this->artisan('users:fix-tn-names')->assertExitCode(0);

        $this->assertSame(
            'Canonform',
            DB::table('users')->where('id', $userId)->value('fullname'),
            'a member whose row holds the canon form must still be found'
        );
    }

    public function test_smoke_no_tn_users(): void
    {
        $this->artisan('users:fix-tn-names')
            ->assertExitCode(0);
    }

    public function test_fixes_tn_user_fullname(): void
    {
        $userId = $this->createTNUser('Alice');

        $this->artisan('users:fix-tn-names')
            ->assertExitCode(0);

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertSame('Alice', $user->fullname);
    }

    public function test_skips_tn_user_with_existing_fullname_without_hyphen(): void
    {
        $userId = $this->createTNUser('Bob');
        DB::table('users')->where('id', $userId)->update(['fullname' => 'Bobby']);

        $this->artisan('users:fix-tn-names')
            ->assertExitCode(0);

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertSame('Bobby', $user->fullname);
    }

    public function test_fixes_tn_user_with_hyphenated_fullname(): void
    {
        $userId = $this->createTNUser('Charlie');
        // fullname is the member's own alias local part, as TN used to send it in
        // the From header. "Charlie-12345" would be left alone: nothing in the
        // address says that name came from it.
        DB::table('users')->where('id', $userId)->update(['fullname' => 'Charlie-g12345']);

        $this->artisan('users:fix-tn-names')
            ->assertExitCode(0);

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertSame('Charlie', $user->fullname);
    }

    public function test_skips_regular_user(): void
    {
        $userId = $this->createRegularUser();

        $this->artisan('users:fix-tn-names')
            ->assertExitCode(0);

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertNull($user->fullname);
    }

    public function test_dry_run_does_not_update(): void
    {
        $userId = $this->createTNUser('Diana');

        $this->artisan('users:fix-tn-names', ['--dry-run' => true])
            ->assertExitCode(0);

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertNull($user->fullname);
    }

    public function test_handles_multiple_tn_users(): void
    {
        $id1 = $this->createTNUser('Eve', '111');
        $id2 = $this->createTNUser('Frank', '222');

        $this->artisan('users:fix-tn-names')
            ->assertExitCode(0);

        $this->assertSame('Eve', DB::table('users')->where('id', $id1)->value('fullname'));
        $this->assertSame('Frank', DB::table('users')->where('id', $id2)->value('fullname'));
    }

    /**
     * Bare addresses have no -gNNN suffix. The old rule needed a hyphen before the
     * @, so "tricia.hayes@" was skipped and "mary-jane@" became "mary". The name is
     * the whole username, prettified the same way as everywhere else.
     */
    public function test_fixes_bare_tn_addresses_from_the_whole_username(): void
    {
        $dotted = $this->createTNUser('fixtn.tricia.hayes', null);
        $hyphenated = $this->createTNUser('fixtn-mary-jane', null);
        $hyphenG = $this->createTNUser('fixtn-mary-grace', null);

        $this->artisan('users:fix-tn-names')->assertExitCode(0);

        $this->assertSame('Fixtn Tricia Hayes', DB::table('users')->where('id', $dotted)->value('fullname'));
        $this->assertSame('Fixtn-Mary-Jane', DB::table('users')->where('id', $hyphenated)->value('fullname'));
        $this->assertSame('Fixtn-Mary-Grace', DB::table('users')->where('id', $hyphenG)->value('fullname'));
    }

    /**
     * Only the -g<digits> before the domain is a suffix: "bibiana-gomes-g4840" is
     * Bibiana-Gomes, not Bibiana.
     */
    public function test_keeps_a_hyphenated_username_behind_a_suffix(): void
    {
        $userId = $this->createTNUser('fixtn-bibiana-gomes', '4840');

        $this->artisan('users:fix-tn-names')->assertExitCode(0);

        $this->assertSame('Fixtn-Bibiana-Gomes', DB::table('users')->where('id', $userId)->value('fullname'));
    }

    /**
     * A hyphenated name keeps matching the command's "fullname contains a hyphen"
     * filter after it is fixed. Rewriting it to the same value on every run is a
     * wasted write, and reporting it as fixed hides how much real work there was.
     */
    public function test_leaves_an_already_correct_hyphenated_name_alone(): void
    {
        $userId = $this->createTNUser('fixtn-ann-lee', '77');
        DB::table('users')->where('id', $userId)->update(['fullname' => 'Fixtn-Ann-Lee']);

        $this->artisan('users:fix-tn-names')
            ->expectsOutputToContain('skipped')
            ->assertExitCode(0);

        $this->assertSame('Fixtn-Ann-Lee', DB::table('users')->where('id', $userId)->value('fullname'));
    }

    /**
     * A fullname is made from the username but says nothing about it afterwards.
     * A hyphen in a name is not evidence the name came from the address: a real
     * "Mary-Jane Smith" must survive, as must a name unrelated to the username.
     */
    public function test_leaves_a_real_hyphenated_name_alone(): void
    {
        $realName = $this->createTNUser('fixtn-tricia.hayes', '12');
        DB::table('users')->where('id', $realName)->update(['fullname' => 'Mary-Jane Smith']);

        $prefix = $this->createTNUser('fixtn-alice', '34');
        DB::table('users')->where('id', $prefix)->update(['fullname' => 'Fixtn-Alice-Smith']);

        $this->artisan('users:fix-tn-names')->assertExitCode(0);

        $this->assertSame('Mary-Jane Smith', DB::table('users')->where('id', $realName)->value('fullname'));
        $this->assertSame('Fixtn-Alice-Smith', DB::table('users')->where('id', $prefix)->value('fullname'));
    }

    /**
     * The raw forms of the member's own address are what the command exists to
     * replace: the whole address, the alias local part and an empty name.
     */
    public function test_fixes_names_that_are_raw_forms_of_the_address(): void
    {
        $address = $this->createTNUser('fixtn-ann.lee', '56');
        DB::table('users')->where('id', $address)->update(['fullname' => 'fixtn-ann.lee-g56@user.trashnothing.com']);

        $alias = $this->createTNUser('fixtn-bo.ng', '78');
        DB::table('users')->where('id', $alias)->update(['fullname' => 'fixtn-bo.ng-g78']);

        $empty = $this->createTNUser('fixtn-cy', '90');
        DB::table('users')->where('id', $empty)->update(['fullname' => '']);

        $this->artisan('users:fix-tn-names')->assertExitCode(0);

        $this->assertSame('Fixtn-Ann Lee', DB::table('users')->where('id', $address)->value('fullname'));
        $this->assertSame('Fixtn-Bo Ng', DB::table('users')->where('id', $alias)->value('fullname'));
        $this->assertSame('Fixtn-Cy', DB::table('users')->where('id', $empty)->value('fullname'));
    }

    /**
     * Only the preferred address names the member. Reading every TN address gave
     * one candidate per address, so a member holding two usernames' addresses was
     * renamed back and forth on alternate runs.
     */
    public function test_names_the_member_from_the_preferred_address_only(): void
    {
        $userId = $this->createTNUser('fixtn-new-name', '11');
        DB::table('users_emails')->insert([
            'userid'    => $userId,
            'email'     => 'fixtn-old-name-g22@user.trashnothing.com',
            'backwards' => strrev('fixtn-old-name-g22@user.trashnothing.com'),
            'preferred' => 0,
            'added'     => now(),
        ]);

        $this->artisan('users:fix-tn-names')->assertExitCode(0);
        $this->assertSame('Fixtn-New-Name', DB::table('users')->where('id', $userId)->value('fullname'));

        // A second run must not flip it to the other address's username.
        $this->artisan('users:fix-tn-names')->assertExitCode(0);
        $this->assertSame('Fixtn-New-Name', DB::table('users')->where('id', $userId)->value('fullname'));
    }

    /**
     * Member addresses live on user.trashnothing.com. Anything else on the TN
     * domain is not a member's address and does not name anyone.
     */
    public function test_skips_addresses_that_are_not_tn_member_addresses(): void
    {
        $userId = $this->createUserWithEmail('fixtn-noreply-12345@trashnothing.com');

        $this->artisan('users:fix-tn-names')->assertExitCode(0);

        $this->assertNull(DB::table('users')->where('id', $userId)->value('fullname'));
    }
}
