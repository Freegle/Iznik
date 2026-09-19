<?php

namespace Tests\Feature\Backup;

use App\Mail\Backup\BackupFailedMail;
use App\Monitoring\HostCommandRunner;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The Laravel replacement for the shell script on the database node.
 *
 * Off by default, so merging it changes nothing. The tests that matter are the two
 * behaviours the script got wrong: a failed xtrabackup masked by a successful upload of the
 * truncated stream, and a desync left on when the script did not reach its last line.
 */
class DatabaseBackupCommandTest extends TestCase
{
    /** Captures the script instead of running it. */
    private function fakeRunner(?string $returns, ?string &$captured = null): void
    {
        $this->app->bind(HostCommandRunner::class, function () use ($returns, &$captured) {
            return new class($returns, $captured) implements HostCommandRunner
            {
                public function __construct(private ?string $returns, private ?string &$captured) {}

                public function run(string $target, string $script): ?string
                {
                    $this->captured = $script;

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
            'xtrabackup' => '/usr/bin/xtrabackup',
            'gsutil' => '/usr/bin/gsutil',
            'bucket' => 'gs://freegle_backup_uk',
            'compress_threads' => 4,
            'alert_email' => 'geek-alerts@example.com',
        ], $overrides));

        config()->set('freegle.backup.drain', [
            'enabled' => false, 'start' => '03:50', 'minutes' => 45, 'always_run' => [],
        ]);
    }

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

    public function test_script_uses_pipefail_so_a_failed_xtrabackup_is_not_masked(): void
    {
        // The script this replaces tested $? after "xtrabackup | gsutil", which is gsutil's
        // status, so a truncated stream that uploaded fine was reported as success.
        $this->configure();
        $captured = null;
        $this->fakeRunner('FREEGLE_BACKUP_RESULT=0', $captured);

        $this->artisan('backup:database')->assertExitCode(0);

        $this->assertStringContainsString('set -o pipefail', $captured);
    }

    public function test_script_releases_the_desync_from_a_trap(): void
    {
        // The script released it on its last line, so a kill or a timeout anywhere above
        // left the node desynced indefinitely.
        $this->configure();
        $captured = null;
        $this->fakeRunner('FREEGLE_BACKUP_RESULT=0', $captured);

        $this->artisan('backup:database')->assertExitCode(0);

        $this->assertStringContainsString('trap ', $captured);
        $this->assertStringContainsString('wsrep_desync = OFF', $captured);
        $this->assertStringContainsString('EXIT', $captured);
        $this->assertStringContainsString('wsrep_desync = ON', $captured);
    }

    public function test_script_streams_compressed_to_the_configured_bucket(): void
    {
        $this->configure(['bucket' => 'gs://somewhere-else', 'compress_threads' => 8]);
        $captured = null;
        $this->fakeRunner('FREEGLE_BACKUP_RESULT=0', $captured);

        $this->artisan('backup:database')->assertExitCode(0);

        $this->assertStringContainsString('--compress-threads=8', $captured);
        $this->assertStringContainsString('gs://somewhere-else/iznik-', $captured);
        $this->assertStringContainsString('.xbstream', $captured);
    }

    public function test_reports_failure_and_alerts_when_the_pipeline_fails(): void
    {
        Mail::fake();
        $this->configure();
        $this->fakeRunner('FREEGLE_BACKUP_RESULT=3');

        $this->artisan('backup:database')->assertExitCode(1);

        Mail::assertSent(BackupFailedMail::class);
    }

    public function test_treats_a_missing_result_as_failure(): void
    {
        // Killed, timed out, or never started. Silence must not read as success.
        Mail::fake();
        $this->configure();
        $this->fakeRunner('some partial output with no marker');

        $this->artisan('backup:database')
            ->expectsOutputToContain('did not report a result')
            ->assertExitCode(1);

        Mail::assertSent(BackupFailedMail::class);
    }

    public function test_treats_an_unreachable_host_as_failure(): void
    {
        Mail::fake();
        $this->configure();
        $this->fakeRunner(null);

        $this->artisan('backup:database')->assertExitCode(1);

        Mail::assertSent(BackupFailedMail::class);
    }

    public function test_the_failure_alert_resolves_its_subject_and_view(): void
    {
        // Mail::fake() records the mailable without rendering it, so a mistyped view name
        // would pass every test above and only throw at send time, which is precisely when
        // the alert is needed.
        $mail = new BackupFailedMail('a detail');

        $this->assertSame('BACKUP ERROR: database backup failed', $mail->envelope()->subject);
        $this->assertSame('emails.backup-failed-text', $mail->content()->text);
        $this->assertTrue(
            view()->exists($mail->content()->text),
            'the alert view must resolve, or the alert throws when it fires'
        );
    }

    public function test_dry_run_prints_the_script_and_runs_nothing(): void
    {
        $this->configure(['enabled' => false]);
        $captured = null;
        $this->fakeRunner('ignored', $captured);

        $this->artisan('backup:database --dry-run')->assertExitCode(0);

        $this->assertNull($captured, 'a dry run must not execute anything');
    }
}
