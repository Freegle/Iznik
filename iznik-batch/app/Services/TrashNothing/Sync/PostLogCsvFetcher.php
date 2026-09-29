<?php

namespace App\Services\TrashNothing\Sync;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the TN post-log CSV (https://trashnothing.com/cimg/fd-post-log.csv),
 * caching it on local disk.
 *
 * The CSV is ~15MB and grows daily, so re-downloading it on every run is slow
 * and wasteful — parity/replay runs are usually repeated over the same data.
 * By default the cached copy is reused if present; pass $forceRefresh (via the
 * commands' --refresh-csv option) to download a fresh copy and replace it.
 *
 * Because a download is now a rare event, it is made as tolerant as possible
 * (long timeouts, retries, stale-cache fallback) rather than failing fast: the
 * cost of waiting out a slow origin is much lower than the cost of a run that
 * dies with no CSV at all.
 */
class PostLogCsvFetcher
{
    private const CSV_URL = 'https://trashnothing.com/cimg/fd-post-log.csv';

    /** Generous but bounded: a dead origin should still fail rather than hang forever. */
    private const CONNECT_TIMEOUT = 60;

    /**
     * Whole-body transfer budget. TN's origin has been seen serving this ~15MB
     * file at ~13KB/s (≈20 minutes), and it grows daily, so allow well past
     * that: Laravel's 30s default aborts a slow-but-working download mid-file.
     */
    private const TRANSFER_TIMEOUT = 3600;

    private const MAX_ATTEMPTS       = 5;
    private const RETRY_DELAY_SECONDS = 15;

    public function cachePath(): string
    {
        return storage_path('app/tn_sync/fd_post_log.csv');
    }

    public function isCached(): bool
    {
        return is_file($this->cachePath()) && filesize($this->cachePath()) > 0;
    }

    /**
     * Returns the CSV text, or null if it isn't cached and the download failed.
     */
    public function fetch(bool $forceRefresh = false): ?string
    {
        $path = $this->cachePath();

        if (!$forceRefresh && $this->isCached()) {
            Log::info('TN sync: using cached post-log CSV', ['path' => $path]);
            return (string) file_get_contents($path);
        }

        $body = $this->download();

        if ($body !== null) {
            return $body;
        }

        Log::error('TN sync: failed to download post-log CSV', [
            'url'      => self::CSV_URL,
            'attempts' => self::MAX_ATTEMPTS,
        ]);

        // A stale cached copy is far more useful than nothing, so fall back to
        // it if --refresh-csv brought us here and the refresh didn't work out.
        if ($this->isCached()) {
            Log::warning('TN sync: falling back to previously cached post-log CSV', ['path' => $path]);
            return (string) file_get_contents($path);
        }

        return null;
    }

    /**
     * Downloads the CSV into the cache, retrying slow/flaky attempts.
     * Returns the CSV text, or null if every attempt failed.
     */
    private function download(): ?string
    {
        $path = $this->cachePath();

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Streamed to a temp file and moved into place only on success, so an
        // aborted download never leaves a truncated CSV to be cached and reused.
        $tmpPath = $path . '.download-' . getmypid();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if ($this->attemptDownload($tmpPath, $attempt)) {
                $bytes = filesize($tmpPath);

                if (!@rename($tmpPath, $path)) {
                    // Not fatal — the caller can still use what we downloaded.
                    Log::warning('TN sync: could not move post-log CSV into cache', ['path' => $path]);
                    $body = (string) file_get_contents($tmpPath);
                    @unlink($tmpPath);
                    return $body;
                }

                Log::info('TN sync: downloaded post-log CSV', [
                    'path'    => $path,
                    'bytes'   => $bytes,
                    'attempt' => $attempt,
                ]);

                return (string) file_get_contents($path);
            }

            @unlink($tmpPath);

            if ($attempt < self::MAX_ATTEMPTS) {
                sleep(self::RETRY_DELAY_SECONDS);
            }
        }

        return null;
    }

    /**
     * One download attempt, streamed to $tmpPath. Returns true if it produced a
     * non-empty file. Never throws: a failed attempt is logged and retried.
     */
    private function attemptDownload(string $tmpPath, int $attempt): bool
    {
        // Append a random suffix to prevent any proxy or CDN from serving a stale copy.
        $url = self::CSV_URL . '?_=' . bin2hex(random_bytes(8));

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TRANSFER_TIMEOUT)
                ->sink($tmpPath)
                ->get($url);

            if ($response->successful() && is_file($tmpPath) && filesize($tmpPath) > 0) {
                return true;
            }

            Log::warning('TN sync: post-log CSV download attempt failed', [
                'status'  => $response->status(),
                'bytes'   => is_file($tmpPath) ? filesize($tmpPath) : 0,
                'attempt' => $attempt,
            ]);
        } catch (\Throwable $e) {
            Log::warning('TN sync: post-log CSV download attempt errored', [
                'error'   => $e->getMessage(),
                'attempt' => $attempt,
            ]);
        }

        return false;
    }
}
