<?php

namespace App\Console\Commands\Images;

use App\Services\ImageStore\ObjectStore;
use App\Services\ImageStore\ObjectStoreUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Prove the object store works the way the edge relies on it: we can write,
 * the store reports the right length, and an ANONYMOUS reader can fetch the
 * object at IMAGE_STORE_PUBLIC_URL.
 *
 * The last part is the one that matters. frontend-nginx asks the bucket for
 * every image the spool no longer holds. A bucket that is not public answers
 * 401 or 403; nginx falls through to the legacy share, and every image that
 * exists only in the bucket 404s with nothing anywhere naming the cause. Run
 * this before enabling the store and after any change to the bucket or its
 * keys. The schedule runs it every ten minutes with --report while the store
 * is enabled, so a bucket that goes dark (2026-09-28: public read and the key
 * both revoked at once, provider side) raises a Sentry error within minutes
 * instead of being found on a status question an hour later.
 */
class ObjectStoreCheckCommand extends Command
{
    protected $signature = 'images:object-store-check
                            {--report : Report a failure to Sentry as well as printing it (used by the schedule)}';

    protected $description = 'Write, read back anonymously and delete a probe object in the image store';

    public function handle(): int
    {
        $failure = $this->check();

        if ($failure === null) {
            return Command::SUCCESS;
        }

        $this->error($failure);

        if ($this->option('report')) {
            report(new ObjectStoreUnavailable('images:object-store-check failed: ' . $failure));
        }

        return Command::FAILURE;
    }

    /** The reason the check failed, or null when it passed. */
    private function check(): ?string
    {
        // Built here rather than injected: the container would hand ObjectStore
        // the DEFAULT disk, and the check would pass against local storage.
        $store = new ObjectStore();
        $key = 'probe-' . bin2hex(random_bytes(8));
        $url = $store->publicUrl($key);

        if ($url === null) {
            return 'IMAGE_STORE_PUBLIC_URL is not set, so nginx would have nowhere to read from.';
        }

        $body = 'freegle image store probe ' . now()->toIso8601String();

        try {
            $store->put($key, $body, 'text/plain');
        } catch (\Throwable $e) {
            return "Could not write {$key}: " . $e->getMessage();
        }

        try {
            $size = $store->sizeOf($key);
            if ($size !== strlen($body)) {
                return "The store reports {$key} as " . var_export($size, true) . ' bytes, expected ' . strlen($body) . '.';
            }

            $response = Http::timeout(15)->get($url);

            if (! $response->ok()) {
                return "Anonymous GET {$url} returned HTTP {$response->status()}. The bucket is not publicly readable; do not enable the store until it is.";
            }

            if ($response->body() !== $body) {
                return "Anonymous GET {$url} returned different bytes from what was written.";
            }
        } catch (\Throwable $e) {
            return 'Check failed: ' . $e->getMessage();
        } finally {
            try {
                $store->delete($key);
            } catch (\Throwable $e) {
                $this->warn("Could not delete the probe {$key}: " . $e->getMessage());
            }
        }

        $this->info("OK: wrote {$key}, the store reports its length, an anonymous GET at {$url} returned it, and it is deleted.");

        return null;
    }
}
