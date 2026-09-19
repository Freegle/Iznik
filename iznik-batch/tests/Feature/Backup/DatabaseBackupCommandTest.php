<?php

namespace Tests\Feature\Backup;

use App\Console\Commands\Backup\DatabaseBackupCommand;
use App\Mail\Backup\BackupFailedMail;
use App\Monitoring\HostCommandRunner;
use App\Monitoring\SshHostCommandRunner;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Laravel replacement for the shell script on the database node.
 *
 * Off by default, so merging it changes nothing. The tests that matter are the behaviours
 * the script got wrong or could not report: a failed xtrabackup masked by a successful
 * upload of the truncated stream, a desync left on when the script did not reach its last
 * line, and a runner whose timeout would kill the backup partway.
 */
class DatabaseBackupCommandTest extends TestCase
{
    /**
     * Captures the script instead of running it.
     *
     * Bound CONTEXTUALLY, the way AppServiceProvider binds the real runner. A plain bind of
     * HostCommandRunner would be ignored for this command, because the contextual binding
     * wins, and the test would quietly try to ssh somewhere.
     */
    private function fakeRunner(?string $returns, ?string &$captured = null, ?string &$target = null): void
    {
        $this->app->when(DatabaseBackupCommand::class)
            ->needs(HostCommandRunner::class)
            ->give(function () use ($returns, &$captured, &$target) {
                return new class($returns, $captured, $target) implements HostCommandRunner
                {
                    public function __construct(
                        private ?string $returns,
                        private ?string &$captured,
                        private ?string &$target,
                    ) {}

                    public function run(string $target, string $script): ?string
                    {
                        $this->captured = $script;
                        $this->target = $target;

                        return $this->returns;
                    }
                };
            });
    }

    private function configure(array $overrides = []): void
    {
        config()->set('freegle.backup.database', array_merge([
            'enabled' => true,
            'host' => 'root@db2',
            'ssh_key' => '/etc/monitoring-ssh-key',
            'ssh_timeout_seconds' => 7200,
            'xtrabackup' => '/usr/bin/xtrabackup',
            'gsutil' => '/usr/bin/gsutil',
            'target_dir' => '/backup',
            'bucket' => 'gs://freegle_backup_uk',
            'compress_threads' => 4,
            'alert_email' => 'geek-alerts@example.com',
        ], $overrides));

        config()->set('freegle.backup.drain', [
            'enabled' => false, 'start' => '03:50', 'minutes' => 45, 'always_run' => [],
        ]);
    }

    /** What a good night looks like from the node. */
    private const GOOD_NIGHT = "xtrabackup: completed OK!\nFREEGLE_BACKUP_RESULT=0\nFREEGLE_BACKUP_RESYNC=ok\n";

    public function test_does_nothing_at_all_when_switched_off(): void
    {
        $this->configure(['enabled' => false]);
        $captured = null;
        $this->fakeRunner('ignored', $captured);

        $this->artisan('backup:database')
            ->expectsOutputToContain('switched off')
            ->assertExitCode(0);

        $this->assertNull($captured, 'nothing should have been run');
    }

    public function test_fails_when_no_host_is_configured(): void
    {
        $this->configure(['host' => '']);
        $this->fakeRunner(null);

        $this->artisan('backup:database')->assertExitCode(1);
    }

    public function test_runs_the_script_on_the_configured_host(): void
    {
        $this->configure(['host' => 'root@db2']);
        $captured = null;
        $target = null;
        $this->fakeRunner(self::GOOD_NIGHT, $captured, $target);

        $this->artisan('backup:database')->assertExitCode(0);

        $this->assertSame('root@db2', $target);
    }

    public function test_production_runner_uses_the_backup_key_and_timeout_not_the_monitoring_ones(): void
    {
        // The monitoring runner gives up after 30 seconds. The backup takes about eighteen
        // minutes, so a command that received that runner would be killed partway through
        // every night and report the node unreachable.
        $this->configure(['ssh_key' => '/etc/some-key', 'ssh_timeout_seconds' => 5400]);
        config()->set('freegle.monitoring.host_ssh_timeout_seconds', 30);

        $command = $this->app->make(DatabaseBackupCommand::class);

        $runner = (fn () => $this->runner)->call($command);

        $this->assertInstanceOf(SshHostCommandRunner::class, $runner);
        $this->assertSame('/etc/some-key', $runner->keyPath);
        $this->assertSame(5400, $runner->timeoutSeconds);
    }

    public function test_script_uses_pipefail_so_a_failed_xtrabackup_is_not_masked(): void
    {
        // The script this replaces tested $? after "xtrabackup | gsutil", which is gsutil's
        // status, so a truncated stream that uploaded fine was reported as success.
        $this->configure();
        $captured = null;
        $this->fakeRunner(self::GOOD_NIGHT, $captured);

        $this->artisan('backup:database')->assertExitCode(0);

        $this->assertStringContainsString('set -o pipefail', $captured);
    }

    public function test_script_releases_the_desync_from_a_trap_and_says_whether_it_worked(): void
    {
        // The script released it on its last line, so a kill or a timeout anywhere above
        // left the node desynced indefinitely.
        $this->configure();
        $captured = null;
        $this->fakeRunner(self::GOOD_NIGHT, $captured);

        $this->artisan('backup:database')->assertExitCode(0);

        $this->assertMatchesRegularExpression('/trap .*wsrep_desync = OFF.* EXIT/', $captured);
        $this->assertStringContainsString('FREEGLE_BACKUP_RESYNC=ok', $captured);
        $this->assertStringContainsString('FREEGLE_BACKUP_RESYNC=failed', $captured);
        $this->assertStringContainsString('wsrep_desync = ON', $captured);
    }

    public function test_script_checks_the_tools_before_it_desyncs_the_node(): void
    {
        // A missing gsutil found AFTER the desync would leave the node out of flow control
        // for nothing. And a desync that does not take is fatal: a backup on a node still in
        // flow control stalls the whole cluster's writes for the duration.
        $this->configure(['xtrabackup' => '/usr/bin/xtrabackup', 'gsutil' => '/opt/gsutil/gsutil']);
        $captured = null;
        $this->fakeRunner(self::GOOD_NIGHT, $captured);

        $this->artisan('backup:database')->assertExitCode(0);

        $check = strpos($captured, 'test -x');
        $desync = strpos($captured, 'wsrep_desync = ON');
        $this->assertNotFalse($check);
        $this->assertNotFalse($desync);
        $this->assertLessThan($desync, $check, 'the tool check must come before the desync');
        $this->assertStringContainsString('/usr/bin/xtrabackup /opt/gsutil/gsutil', $captured);
        $this->assertMatchesRegularExpression('/if ! mysql --execute "SET GLOBAL wsrep_desync = ON"; then\s+echo "FREEGLE_BACKUP_ABORT=/', $captured);
    }

    public function test_script_streams_compressed_to_the_configured_bucket(): void
    {
        $this->configure(['bucket' => 'gs://somewhere-else', 'compress_threads' => 8, 'target_dir' => '/scratch']);
        $captured = null;
        $this->fakeRunner(self::GOOD_NIGHT, $captured);

        $this->artisan('backup:database')->assertExitCode(0);

        $this->assertStringContainsString('--compress-threads=8', $captured);
        $this->assertStringContainsString('--target-dir=/scratch', $captured);
        $this->assertStringContainsString('gs://somewhere-else/iznik-', $captured);
        $this->assertStringContainsString('.xbstream', $captured);
    }

    public function test_dry_run_prints_the_script_and_runs_nothing(): void
    {
        $this->configure(['enabled' => false]);
        $captured = null;
        $this->fakeRunner('ignored', $captured);

        $this->artisan('backup:database --dry-run')
            ->expectsOutputToContain('set -o pipefail')
            ->assertExitCode(0);

        $this->assertNull($captured);
    }

    public function test_reports_failure_and_alerts_when_the_pipeline_fails(): void
    {
        Mail::fake();
        $this->configure();
        $this->fakeRunner("xtrabackup: Error: something\nFREEGLE_BACKUP_RESULT=1\nFREEGLE_BACKUP_RESYNC=ok\n");

        $this->artisan('backup:database')->assertExitCode(1);

        Mail::assertSent(BackupFailedMail::class, function (BackupFailedMail $mail) {
            return $mail->hasTo('geek-alerts@example.com')
                && str_contains($mail->detail, 'failed with status 1')
                && str_contains($mail->detail, 'xtrabackup: Error: something');
        });
    }

    public function test_a_missing_result_is_a_failure_not_a_success(): void
    {
        // Killed, timed out, or never started: no marker means no backup.
        Mail::fake();
        $this->configure();
        $this->fakeRunner("some output but no marker\n");

        $this->artisan('backup:database')->assertExitCode(1);

        Mail::assertSent(BackupFailedMail::class, fn (BackupFailedMail $mail) => str_contains($mail->detail, 'did not report a result'));
    }

    public function test_an_unreachable_host_is_a_failure(): void
    {
        Mail::fake();
        $this->configure();
        $this->fakeRunner(null);

        $this->artisan('backup:database')->assertExitCode(1);

        Mail::assertSent(BackupFailedMail::class, fn (BackupFailedMail $mail) => str_contains($mail->detail, 'Could not reach root@db2'));
    }

    public function test_an_abort_before_the_desync_reports_its_reason(): void
    {
        Mail::fake();
        $this->configure();
        $this->fakeRunner("FREEGLE_BACKUP_ABORT=/opt/gsutil/gsutil is not executable on db-2\n");

        $this->artisan('backup:database')->assertExitCode(1);

        Mail::assertSent(BackupFailedMail::class, function (BackupFailedMail $mail) {
            return str_contains($mail->detail, 'stopped before desyncing the node')
                && str_contains($mail->detail, '/opt/gsutil/gsutil is not executable on db-2');
        });
    }

    public function test_a_good_backup_that_leaves_the_node_desynced_still_alerts(): void
    {
        // The backup is fine. The node is not, and nothing else would say so.
        Mail::fake();
        $this->configure();
        $this->fakeRunner("FREEGLE_BACKUP_RESULT=0\nFREEGLE_BACKUP_RESYNC=failed\n");

        $this->artisan('backup:database')->assertExitCode(1);

        Mail::assertSent(BackupFailedMail::class, fn (BackupFailedMail $mail) => str_contains($mail->detail, 'still desynced'));
    }

    public function test_the_alert_renders(): void
    {
        // Mail::fake() records a mailable without rendering it, so a mistyped view name
        // would pass every other test and throw only when the alert fires.
        $mail = new BackupFailedMail('Backup of root@db2 failed with status 1.');

        $rendered = $mail->render();

        $this->assertSame('BACKUP ERROR: database backup failed', $mail->envelope()->subject);
        $this->assertStringContainsString('failed with status 1', $rendered);
        $this->assertStringContainsString('wsrep_desync', $rendered);
    }
}
