<?php

namespace App\Services;

/**
 * Compresses and prunes the Laravel batch log directory (storage/logs).
 *
 * The Monolog 'daily' channel rotates app logs into dated files but does not
 * compress them, and the supervisor-written logs (scheduler.log, worker.log,
 * ...) are not Monolog-managed at all. This service gzip-compresses rotated
 * (non-live) log files and deletes anything older than the retention window,
 * keeping storage/logs bounded. It is driven daily by the logs:rotate command.
 */
class LogRotationService
{
    /**
     * Delete log files whose modification time is older than $days days.
     *
     * Recurses into subdirectories (e.g. logs/cron). Never deletes .gitignore.
     *
     * @return array{deleted:int, bytes:int, files:list<string>}
     */
    public function prune(string $dir, int $days, bool $dryRun = false): array
    {
        $deleted = 0;
        $bytes = 0;
        $files = [];
        $cutoff = time() - ($days * 86400);

        foreach ($this->listFiles($dir) as $path) {
            if (basename($path) === '.gitignore') {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime >= $cutoff) {
                continue;
            }

            $bytes += @filesize($path) ?: 0;
            if (!$dryRun) {
                @unlink($path);
            }
            $deleted++;
            $files[] = $path;
        }

        return ['deleted' => $deleted, 'bytes' => $bytes, 'files' => $files];
    }

    /**
     * Gzip-compress rotated (non-live) log files in $dir.
     *
     * Targets files named like "*.log" or "*.log.<n>" (e.g. supervisor backups)
     * last modified before today. Files written today are assumed to still be
     * live (a daemon may hold the handle open) and are left alone, as are files
     * already compressed (.gz). The original is removed only after a successful
     * gzip. The file list is materialised before any mutation so newly created
     * .gz files are never re-scanned mid-run.
     *
     * @return array{compressed:int, bytes_before:int, bytes_after:int, files:list<string>}
     */
    public function compress(string $dir, bool $dryRun = false): array
    {
        $compressed = 0;
        $bytesBefore = 0;
        $bytesAfter = 0;
        $files = [];
        $todayStart = strtotime('today');

        foreach ($this->listFiles($dir) as $path) {
            // Only rotate actual log files: foo.log or foo.log.N (numbered backups).
            if (!preg_match('/\.log(\.\d+)?$/', basename($path))) {
                continue;
            }
            // Leave files written today - they may still be open and appended to.
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime >= $todayStart) {
                continue;
            }

            $bytesBefore += @filesize($path) ?: 0;
            $compressed++;
            $files[] = $path;

            if ($dryRun) {
                continue;
            }

            $dest = $path.'.gz';
            if ($this->gzipFile($path, $dest)) {
                @unlink($path);
                $bytesAfter += @filesize($dest) ?: 0;
            }
        }

        return [
            'compressed' => $compressed,
            'bytes_before' => $bytesBefore,
            'bytes_after' => $bytesAfter,
            'files' => $files,
        ];
    }

    /**
     * Stream a file through gzip without loading it entirely into memory.
     */
    private function gzipFile(string $source, string $dest): bool
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            return false;
        }
        $out = @gzopen($dest, 'wb6');
        if ($out === false) {
            fclose($in);

            return false;
        }

        while (!feof($in)) {
            $chunk = fread($in, 1 << 20); // 1 MiB
            if ($chunk === false) {
                break;
            }
            gzwrite($out, $chunk);
        }

        fclose($in);
        gzclose($out);

        return true;
    }

    /**
     * List all files (not directories) under $dir, recursively, materialised
     * into an array so the caller can safely mutate the directory while looping.
     *
     * @return list<string>
     */
    private function listFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
