<?php

namespace Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    public function test_creates_user_with_email_and_name(): void
    {
        $email = $this->uniqueEmail('newuser');

        $this->artisan("user:create --email={$email} --name=\"New Person\"")
            ->assertExitCode(0);

        $this->assertDatabaseHas('users_emails', ['email' => $email]);
        $this->assertDatabaseHas('users', ['fullname' => 'New Person']);
    }

    public function test_fails_if_email_already_exists(): void
    {
        $user = $this->createTestUser();
        $email = DB::table('users_emails')->where('userid', $user->id)->value('email');

        $this->artisan("user:create --email={$email} --name=\"Someone Else\"")
            ->assertExitCode(1);
    }

    public function test_fails_if_email_not_provided(): void
    {
        $this->artisan('user:create --name="Test Person"')
            ->assertExitCode(1);
    }

    public function test_fails_if_name_not_provided(): void
    {
        $email = $this->uniqueEmail('newuser');

        $this->artisan("user:create --email={$email}")
            ->assertExitCode(1);
    }

    public function test_creates_native_login_when_password_provided(): void
    {
        $email = $this->uniqueEmail('newuser');

        $this->artisan("user:create --email={$email} --name=\"New Person\" --password=secret123")
            ->assertExitCode(0);

        $userId = DB::table('users_emails')->where('email', $email)->value('userid');
        $this->assertNotNull($userId);

        $this->assertDatabaseHas('users_logins', [
            'userid' => $userId,
            'type' => 'Native',
            'uid' => $email,
        ]);
    }

    public function test_sets_preferred_email(): void
    {
        $email = $this->uniqueEmail('newuser');

        $this->artisan("user:create --email={$email} --name=\"New Person\"")
            ->assertExitCode(0);

        $this->assertDatabaseHas('users_emails', ['email' => $email, 'preferred' => 1]);
    }

    public function test_outputs_created_user_id(): void
    {
        $email = $this->uniqueEmail('newuser');

        $this->artisan("user:create --email={$email} --name=\"New Person\"")
            ->expectsOutputToContain('Created user')
            ->assertExitCode(0);
    }
}
