<?php

namespace Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnspamUserCommandTest extends TestCase
{
    public function test_removes_user_from_spam_and_banned_lists(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $email = DB::table('users_emails')->where('userid', $user->id)->value('email');

        DB::table('users_banned')->insert(['userid' => $user->id, 'groupid' => $group->id]);
        DB::table('spam_users')->insert(['userid' => $user->id, 'byuserid' => $user->id, 'added' => now(), 'collection' => 'Spammer']);

        $this->artisan("user:unspam --email={$email}")
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users_banned', ['userid' => $user->id]);
        $this->assertDatabaseMissing('spam_users', ['userid' => $user->id]);
    }

    public function test_succeeds_even_if_user_not_in_spam_list(): void
    {
        $user = $this->createTestUser();
        $email = DB::table('users_emails')->where('userid', $user->id)->value('email');

        $this->artisan("user:unspam --email={$email}")
            ->assertExitCode(0);
    }

    public function test_fails_if_email_not_provided(): void
    {
        $this->artisan('user:unspam')
            ->assertExitCode(1);
    }

    public function test_fails_if_user_not_found(): void
    {
        $this->artisan('user:unspam --email=notexists@example.com')
            ->assertExitCode(1);
    }

    public function test_outputs_result(): void
    {
        $user = $this->createTestUser();
        $email = DB::table('users_emails')->where('userid', $user->id)->value('email');

        $this->artisan("user:unspam --email={$email}")
            ->expectsOutputToContain('Unspammed')
            ->assertExitCode(0);
    }
}
