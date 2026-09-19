<?php

namespace App\Console\Commands\Backup;

use App\Console\BackupDrain;
use App\Mail\Backup\BackupFailedMail;
use App\Monitoring\HostCommandRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Takes the nightly physical database backup, replacing the shell script that has lived on
 * the database node at /var/www/iznik/scripts/backup.
 *
 * OFF by default. Until `backup.database.enabled` is set, this refuses to run and the shell
 * script remains what actually takes the backup. Nothing about the schedule below changes
 * that: the scheduled entry is also gated on the same flag.
 *
 * xtrabackup copies a local data directory, so the whole pipeline runs on the database node
 * and only control flow crosses ssh. That is the same shape as the deferral probe and the
 * relay log ingest, and it uses the same HostCommandRunner abstraction so it can be tested
 * without a host.
 *
 * Two things are deliberately different from the script it replaces:
 *
 *  - **`set -o pipefail`.** The script ran `xtrabackup ... | gsutil cp -` and then tested
 *    `$?`, which in bash is the status of the LAST command in the pipeline. An xtrabackup
 *    that died halfway still left gsutil uploading the truncated stream and exiting 0, so
 *    the script sent "BACKUP SUCCESS" for an incomplete backup. Only the nightly Yesterday
 *    restore would have caught it.
 *  - **The desync is released from a trap.** The script turned `wsrep_desync` back off on
 *    its last line, so a kill, a timeout or a failure anywhere above it left the node
 *    desynced indefinitely. A trap on EXIT releases it whatever happens.
 */
class DatabaseBackupCommand extends Command
{
    protected $signature = 'backup:database
        {--dry-run : Print the script that would run on the database node, and run nothing}';

    protected $description = 'Take the nightly physical database backup (off unless BACKUP_DB_ENABLED is set)';

    /** Echoed by the remote script so we can read the pipeline status back over ssh. */
    private const RESULT_MARKER = 'FREEGLE_BACKUP_RESULT=';

    public function handle(HostCommandRunner $runner): int
    {
        $config = (array) config('freegle.backup.database', []);
        $dryRun = (bool) $this->option('dry-run');

        if (! ($config['enabled'] ?? false) && ! $dryRun) {
            $this->warn('Database backup is switched off (BACKUP_DB_ENABLED). Nothing done.');

            return self::SUCCESS;
        }

        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '') {
            $this->error('No backup host configured (BACKUP_DB_HOST).');

            return self::FAILURE;
        }

        // Worth knowing, not worth stopping for. A backup that did not run is worse than
        // one that ran alongside some batch work.
        if (! BackupDrain::active()) {
            $this->warn('Batch work is not being held off; the backup will compete with it.');
        }

        $script = $this->script($config);

        if ($dryRun) {
            $this->line($script);

            return self::SUCCESS;
        }

        $this->info("Backing up {$host} to ".($config['bucket'] ?? '').' ...');

        $output = $runner->run($host, $script);

        if ($output === null) {
            return $this->failed($config, "Could not reach {$host}.");
        }

        $status = $this->resultFrom($output);

        if ($status === null) {
            return $this->failed($config, "Backup of {$host} did not report a result; treat it as failed.");
        }

        if ($status !== 0) {
            return $this->failed($config, "Backup of {$host} failed with status {$status}.");
        }

        $this->info('Backup complete.');
        Log::info('Database backup complete', ['host' => $host]);

        return self::SUCCESS;
    }

    /**
     * The script run on the database node.
     *
     * @param  array<string,mixed>  $config
     */
    private function script(array $config): string
    {
        $xtrabackup = (string) ($config['xtrabackup'] ?? '/usr/bin/xtrabackup');
        $gsutil = (string) ($config['gsutil'] ?? 'gsutil');
        $bucket = rtrim((string) ($config['bucket'] ?? ''), '/');
        $threads = max(1, (int) ($config['compress_threads'] ?? 4));
        $target = $bucket.'/iznik-'.date('Y-m-d-H-i').'.xbstream';

        // pipefail so a failed xtrabackup is not masked by a successful upload of the
        // truncated stream. The trap releases the desync whatever happens, including a
        // kill or an ssh timeout.
        return <<<SH
            set -o pipefail
            trap 'mysql --execute "SET GLOBAL wsrep_desync = OFF" >/dev/null 2>&1' EXIT

            mysql --execute "SET GLOBAL wsrep_desync = ON"

            {$xtrabackup} --backup --stream=xbstream --compress --compress-threads={$threads} \
              | {$gsutil} cp - {$target}
            rc=\$?

            echo "{$this->marker()}\$rc"
            exit \$rc
            SH;
    }

    private function marker(): string
    {
        return self::RESULT_MARKER;
    }

    /**
     * The pipeline's exit status, or null if the script never reported one (killed,
     * timed out, or never started).
     */
    private function resultFrom(string $output): ?int
    {
        if (! preg_match('/'.preg_quote(self::RESULT_MARKER, '/').'(\d+)/', $output, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function failed(array $config, string $message): int
    {
        $this->error($message);
        Log::error('Database backup failed', ['detail' => $message]);

        $to = trim((string) ($config['alert_email'] ?? ''));
        if ($to !== '') {
            \Illuminate\Support\Facades\Mail::to($to)->send(new BackupFailedMail($message));
        }

        return self::FAILURE;
    }
}
