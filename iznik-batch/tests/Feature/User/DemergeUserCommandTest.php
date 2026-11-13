<?php

namespace Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemergeUserCommandTest extends TestCase
{
    private array $cleanupUserIds = [];

    // DatabaseTransactions opens a transaction on the default connection, which prevents
    // a separate PDO connection (the simulated backup DB) from seeing uncommitted rows.
    // We disable it for this class and handle cleanup manually.
    public function beginDatabaseTransaction(): void {}

    /**
     * The options that make the test database its own backup, on the engine the suite is running on. The
     * command reads the backup through a second connection.
     */
    private function backupIsTheTestDatabase(): string
    {
        if (! $this->onPostgres()) {
            return '--backup-host=percona --backup-port=3306 --backup-password=iznik --backup-db=iznik_batch_test';
        }
        $c = config('database.connections.pgsql');

        return "--backup-host={$c['host']} --backup-port={$c['port']} --backup-user={$c['username']} --backup-password={$c['password']} --backup-db=".DB::getDatabaseName();
    }

    protected function tearDown(): void
    {
        if ($this->cleanupUserIds) {
            DB::table('users_emails')->whereIn('userid', $this->cleanupUserIds)->delete();
            DB::table('users')->whereIn('id', $this->cleanupUserIds)->delete();
            $this->cleanupUserIds = [];
        }
        parent::tearDown();
    }

    public function test_fails_if_email_not_provided(): void
    {
        $this->artisan('user:demerge --from-email=other@test.com --backup-host=percona --backup-password=iznik')
            ->assertExitCode(1);
    }

    public function test_fails_if_from_email_not_provided(): void
    {
        $this->artisan('user:demerge --email=someone@test.com --backup-host=percona --backup-password=iznik')
            ->assertExitCode(1);
    }

    public function test_fails_if_backup_host_not_provided(): void
    {
        $this->artisan('user:demerge --email=someone@test.com --from-email=other@test.com --backup-password=iznik')
            ->assertExitCode(1);
    }

    public function test_fails_if_user_not_found_on_backup(): void
    {
        // Using test DB as backup — user won't be found since we use random emails
        $this->artisan("user:demerge --email=notexists123@example.com --from-email=notexists456@example.com {$this->backupIsTheTestDatabase()}")
            ->assertExitCode(1);
    }

    public function test_refuses_when_both_emails_belong_to_the_same_user(): void
    {
        // Two addresses of one account is not a merge, so there is nothing to undo.
        // Here live and backup are the same DB, so a run that proceeds would delete
        // the account's own rows.
        $suffix = uniqid();
        $email = "demerge_same_a_{$suffix}@example.com";
        $otherEmail = "demerge_same_b_{$suffix}@example.com";

        $userId = DB::table('users')->insertGetId(['fullname' => 'Demerge Test Same', 'added' => now()]);
        DB::table('users_emails')->insert(['userid' => $userId, 'email' => $email, 'added' => now()]);
        DB::table('users_emails')->insert(['userid' => $userId, 'email' => $otherEmail, 'added' => now()]);
        $this->cleanupUserIds[] = $userId;

        $this->artisan("user:demerge --email={$email} --from-email={$otherEmail} {$this->backupIsTheTestDatabase()}")
            ->assertExitCode(1);

        $this->assertEquals(2, DB::table('users_emails')->where('userid', $userId)->count());
    }

    public function test_runs_without_error_when_no_rows_to_demerge(): void
    {
        // Use the test DB as both live and backup. Since DatabaseTransactions is disabled,
        // rows are auto-committed and visible to the backup connection.
        // The demerge finds the users but deletes 0 rows (IDs don't match across the lookup).
        $suffix = uniqid();
        $email = "demerge_a_{$suffix}@example.com";
        $fromEmail = "demerge_b_{$suffix}@example.com";

        $userId = DB::table('users')->insertGetId(['fullname' => 'Demerge Test A', 'added' => now()]);
        DB::table('users_emails')->insert(['userid' => $userId, 'email' => $email, 'added' => now()]);
        $this->cleanupUserIds[] = $userId;

        $fromUserId = DB::table('users')->insertGetId(['fullname' => 'Demerge Test B', 'added' => now()]);
        DB::table('users_emails')->insert(['userid' => $fromUserId, 'email' => $fromEmail, 'added' => now()]);
        $this->cleanupUserIds[] = $fromUserId;

        $this->artisan("user:demerge --email={$email} --from-email={$fromEmail} {$this->backupIsTheTestDatabase()}")
            ->assertExitCode(0);
    }
}
