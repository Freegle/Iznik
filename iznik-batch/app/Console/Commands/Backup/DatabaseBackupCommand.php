<?php

namespace App\Console\Commands\Backup;

use App\Console\BackupDrain;
use App\Mail\Backup\BackupFailedMail;
use App\Monitoring\HostCommandRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
 * The runner is injected through the constructor, not handle(): the container's contextual
 * bindings only apply while it is BUILDING a class, so a runner asked for as a handle()
 * parameter would be the monitoring one, with its 30-second timeout, and an 18-minute backup
 * would be killed at 30 seconds. AppServiceProvider binds this command's runner with the
 * backup key and timeout.
 *
 * Things deliberately different from the script it replaces:
 *
 *  - **`set -o pipefail`.** The script ran `xtrabackup ... | gsutil cp -` and then tested
 *    `$?`, which in bash is the status of the LAST command in the pipeline. An xtrabackup
 *    that died halfway still left gsutil uploading the truncated stream and exiting 0, so
 *    the script sent "BACKUP SUCCESS" for an incomplete backup. Only the nightly Yesterday
 *    restore would have caught it.
 *  - **The desync is released from a trap.** The script turned `wsrep_desync` back off on
 *    its last line, so a kill, a timeout or a failure anywhere above it left the node
 *    desynced indefinitely. A trap on EXIT releases it whatever happens, and reports whether
 *    the release worked, because a node quietly left desynced is the failure this exists
 *    to prevent.
 *  - **The tools are checked before the node is desynced**, and a desync that does not
 *    take is fatal. A backup taken on a node still inside flow control stalls the whole
 *    cluster's writes for the duration, which is worse than no backup.
 */
class DatabaseBackupCommand extends Command
{
    protected $signature = 'backup:database
        {--dry-run : Print the script that would run on the database node, and run nothing}';

    protected $description = 'Take the nightly physical database backup (off unless BACKUP_DB_ENABLED is set)';

    /** Echoed by the remote script so we can read the pipeline status back over ssh. */
    private const RESULT_MARKER = 'FREEGLE_BACKUP_RESULT=';

    /** Echoed when the script stopped before desyncing the node, with the reason. */
    private const ABORT_MARKER = 'FREEGLE_BACKUP_ABORT=';

    /** Echoed by the EXIT trap: did the node rejoin flow control? */
    private const RESYNC_MARKER = 'FREEGLE_BACKUP_RESYNC=';

    /** How much of the node's output to keep in a failure alert. */
    private const ALERT_TAIL_LINES = 40;

    public function __construct(private readonly HostCommandRunner $runner)
    {
        parent::__construct();
    }

    public function handle(): int
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

        $output = $this->runner->run($host, $script);

        if ($output === null) {
            return $this->failed($config, "Could not reach {$host}, or the backup ran past its ssh timeout.");
        }

        if (($reason = $this->markerText($output, self::ABORT_MARKER)) !== null) {
            return $this->failed($config, "Backup of {$host} stopped before desyncing the node: {$reason}", $output);
        }

        $status = $this->markerText($output, self::RESULT_MARKER);
        $resync = $this->markerText($output, self::RESYNC_MARKER);

        if ($status === null || ! ctype_digit($status)) {
            return $this->failed($config, "Backup of {$host} did not report a result; treat it as failed.", $output);
        }

        if ((int) $status !== 0) {
            return $this->failed($config, "Backup of {$host} failed with status {$status}.", $output);
        }

        if ($resync !== 'ok') {
            // The backup is good. The node is not: it is still desynced, and nothing else
            // would say so.
            return $this->failed(
                $config,
                "Backup of {$host} completed, but releasing wsrep_desync afterwards failed; the node is still desynced.",
                $output
            );
        }

        $this->info('Backup complete.');
        Log::info('Database backup complete', ['host' => $host]);

        return self::SUCCESS;
    }

    /**
     * The script run on the database node.
     *
     * Everything the node prints, including xtrabackup's log and gsutil's progress on
     * stderr, is folded into stdout so it comes back over ssh and can go in the alert.
     *
     * @param  array<string,mixed>  $config
     */
    private function script(array $config): string
    {
        $xtrabackup = (string) ($config['xtrabackup'] ?? '/usr/bin/xtrabackup');
        $gsutil = (string) ($config['gsutil'] ?? 'gsutil');
        $targetDir = (string) ($config['target_dir'] ?? '/backup');
        $bucket = rtrim((string) ($config['bucket'] ?? ''), '/');
        $threads = max(1, (int) ($config['compress_threads'] ?? 4));
        $target = $bucket.'/iznik-'.date('Y-m-d-H-i').'.xbstream';

        $abort = self::ABORT_MARKER;
        $resync = self::RESYNC_MARKER;
        $result = self::RESULT_MARKER;

        // pipefail so a failed xtrabackup is not masked by a successful upload of the
        // truncated stream. The trap releases the desync whatever happens, including a
        // kill or an ssh timeout, and says whether it managed to.
        return <<<SH
            set -o pipefail
            exec 2>&1

            for tool in {$xtrabackup} {$gsutil}; do
                if ! test -x "\$tool"; then
                    echo "{$abort}\$tool is not executable on \$(hostname)"
                    exit 1
                fi
            done

            if ! mysql --execute "SET GLOBAL wsrep_desync = ON"; then
                echo "{$abort}could not set wsrep_desync = ON"
                exit 1
            fi

            trap 'if mysql --execute "SET GLOBAL wsrep_desync = OFF"; then echo "{$resync}ok"; else echo "{$resync}failed"; fi' EXIT

            {$xtrabackup} --backup --stream=xbstream --target-dir={$targetDir} --compress --compress-threads={$threads} \
              | {$gsutil} cp - {$target}
            rc=\$?

            echo "{$result}\$rc"
            exit \$rc
            SH;
    }

    /**
     * The text after a marker, or null if the script never printed it (killed, timed out,
     * or never got that far).
     */
    private function markerText(string $output, string $marker): ?string
    {
        if (! preg_match('/^'.preg_quote($marker, '/').'(.*)$/m', $output, $m)) {
            return null;
        }

        return trim($m[1]);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function failed(array $config, string $message, ?string $output = null): int
    {
        $this->error($message);
        Log::error('Database backup failed', ['detail' => $message]);

        $detail = $message;
        if ($output !== null && trim($output) !== '') {
            $lines = preg_split('/\r\n|\r|\n/', trim($output)) ?: [];
            $tail = implode("\n", array_slice($lines, -self::ALERT_TAIL_LINES));
            $this->line($tail);
            $detail .= "\n\nLast ".self::ALERT_TAIL_LINES." lines from the node:\n\n".$tail;
        }

        $to = trim((string) ($config['alert_email'] ?? ''));
        if ($to !== '') {
            Mail::to($to)->send(new BackupFailedMail($detail));
        }

        return self::FAILURE;
    }
}
