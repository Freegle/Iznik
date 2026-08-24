<?php

namespace Tests\Feature\User;

use App\Models\User;
use App\Models\UserEmail;
use App\Services\UserManagementService;
use Tests\TestCase;

class UserCommandsTest extends TestCase
{
    public function test_process_bounced_command_runs_successfully(): void
    {
        $this->artisan('mail:bounced')
            ->assertExitCode(0);
    }

    public function test_process_bounced_displays_stats(): void
    {
        $this->artisan('mail:bounced')
            ->expectsOutputToContain('Processing bounced emails')
            ->expectsOutputToContain('Processed:')
            ->expectsOutputToContain('Marked invalid:')
            ->assertExitCode(0);
    }

    public function test_process_bounced_with_bounced_email(): void
    {
        $user = $this->createTestUser();

        // Add a bounced email.
        UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('bounced'),
            'bounced' => now()->subDays(1),
            'added' => now()->subDays(30),
        ]);

        $this->artisan('mail:bounced')
            ->assertExitCode(0);
    }

    public function test_cleanup_command_runs_successfully(): void
    {
        $this->artisan('users:cleanup')
            ->assertExitCode(0);
    }

    public function test_cleanup_displays_table(): void
    {
        $this->artisan('users:cleanup')
            ->expectsOutputToContain('Running user cleanup')
            ->expectsOutputToContain('Delete Yahoo Groups users')
            ->expectsOutputToContain('Forget inactive users')
            ->expectsOutputToContain('Process GDPR forgets')
            ->expectsOutputToContain('Delete fully forgotten users')
            ->assertExitCode(0);
    }

    public function test_cleanup_dry_run(): void
    {
        $this->artisan('users:cleanup --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);
    }

    /**
     * Create a user who matches every forgetInactiveUsers() criterion: inactive
     * for more than six months, plain systemrole, never deleted or forgotten.
     */
    private function createInactiveUser(): User
    {
        return $this->createTestUser([
            'lastaccess' => now()->subMonths(7),
            'systemrole' => User::SYSTEMROLE_USER,
        ]);
    }

    /**
     * Members are deliberately out of scope: forgetInactiveUsers() anti-joins on
     * memberships, so someone who joined a community keeps their data however long
     * they stay away. V1 (User::userRetention) worked the same way. Without this
     * guard the job would strip millions of dormant members, and because forgetUser()
     * deletes users_emails they could never log back in.
     */
    public function test_forget_inactive_leaves_members_alone(): void
    {
        $user = $this->createInactiveUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        app(UserManagementService::class)->forgetInactiveUsers();

        $this->assertNull($user->fresh()->forgotten, 'A member must not be forgotten for inactivity');
    }

    public function test_forget_inactive_forgets_users_with_no_memberships(): void
    {
        $user = $this->createInactiveUser();

        app(UserManagementService::class)->forgetInactiveUsers();

        $forgotten = $user->fresh();
        $this->assertNotNull($forgotten->forgotten, 'An inactive non-member should be forgotten');
        $this->assertNull($forgotten->firstname);
        $this->assertSame("Deleted User #{$user->id}", $forgotten->fullname);
    }

    public function test_forget_inactive_dry_run_changes_nothing(): void
    {
        $user = $this->createInactiveUser();

        app(UserManagementService::class)->forgetInactiveUsers(TRUE);

        $unchanged = $user->fresh();
        $this->assertNull($unchanged->forgotten, 'Dry run must not forget anyone');
        $this->assertSame('Test', $unchanged->firstname, 'Dry run must not clear personal fields');
    }

    public function test_forget_inactive_respects_limit(): void
    {
        // Three candidates, but only one may be processed per run.
        $this->createInactiveUser();
        $this->createInactiveUser();
        $this->createInactiveUser();

        $count = app(UserManagementService::class)->forgetInactiveUsers(FALSE, 1);

        $this->assertLessThanOrEqual(1, $count, 'The limit must cap how many users a run touches');
    }
}
