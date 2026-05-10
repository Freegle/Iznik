<?php

namespace Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FixTNNamesCommandTest extends TestCase
{
    private function createTNUser(string $namePart, string $groupId = '12345'): int
    {
        $userId = DB::table('users')->insertGetId([
            'firstname' => null,
            'lastname'  => null,
            'fullname'  => null,
            'added'     => now(),
        ]);

        $email = "{$namePart}-{$groupId}@trashnothing.com";

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
        // fullname contains a hyphen — previously set from a stale TN sync
        DB::table('users')->where('id', $userId)->update(['fullname' => 'Charlie-12345']);

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
}
