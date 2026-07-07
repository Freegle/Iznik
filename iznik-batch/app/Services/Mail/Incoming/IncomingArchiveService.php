<?php

namespace App\Services\Mail\Incoming;

use Illuminate\Support\Facades\Log;

/**
 * Archives incoming emails to disk for reprocessing safety net.
 *
 * Saves raw email + envelope as JSON files, kept for 48 hours.
 * Format matches the legacy shadow-testing archive for compatibility
 * with ReplayIncomingArchiveCommand.
 */
class IncomingArchiveService
{
    private const ARCHIVE_VERSION = 3;

    private string $archiveDir;

    public function __construct()
    {
        $this->archiveDir = storage_path('incoming-archive');
    }

    /**
     * Archive an incoming email before routing.
     *
     * Returns the archive file path, or null if archiving failed.
     */
    public function archive(string $rawEmail, string $envelopeFrom, string $envelopeTo): ?string
    {
        $dateDir = $this->archiveDir.'/'.date('Y-m-d');

        if (! is_dir($dateDir)) {
            if (! @mkdir($dateDir, 0755, TRUE)) {
                Log::warning('Failed to create archive directory', ['dir' => $dateDir]);

                return NULL;
            }
        }

        $filename = date('His').'_'.mt_rand(100000, 999999).'.json';
        $path = $dateDir.'/'.$filename;

        $data = [
            'version' => self::ARCHIVE_VERSION,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'envelope' => [
                'from' => $envelopeFrom,
                'to' => $envelopeTo,
            ],
            'raw_email' => base64_encode($rawEmail),
        ];

        if (@file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES)) === FALSE) {
            Log::warning('Failed to write archive file', ['path' => $path]);

            return NULL;
        }

        return $path;
    }

    /**
     * Update an archive file with routing outcome after processing.
     */
    public function recordOutcome(string $path, string $outcome): void
    {
        $data = json_decode(@file_get_contents($path), TRUE);
        if ($data === NULL) {
            return;
        }

        $data['routing_outcome'] = $outcome;

        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Delete archive files older than the given number of hours.
     *
     * Returns the number of files deleted.
     */
    public function cleanup(int $maxAgeHours = 48): int
    {
        if (! is_dir($this->archiveDir)) {
            return 0;
        }

        $cutoff = time() - ($maxAgeHours * 3600);
        $deleted = 0;

        $dateDirs = @scandir($this->archiveDir);
        if ($dateDirs === FALSE) {
            return 0;
        }

        foreach ($dateDirs as $dateDir) {
            if ($dateDir === '.' || $dateDir === '..') {
                continue;
            }

            $fullDir = $this->archiveDir.'/'.$dateDir;
            if (! is_dir($fullDir)) {
                continue;
            }

            $files = @scandir($fullDir);
            if ($files === FALSE) {
                continue;
            }

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $fullPath = $fullDir.'/'.$file;
                if (filemtime($fullPath) < $cutoff) {
                    @unlink($fullPath);
                    $deleted++;
                }
            }

            // Remove empty date directories.
            $remaining = @scandir($fullDir);
            if ($remaining !== FALSE && count($remaining) === 2) {
                @rmdir($fullDir);
            }
        }

        return $deleted;
    }
}
