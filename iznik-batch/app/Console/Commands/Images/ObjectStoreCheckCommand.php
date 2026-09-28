<?php

namespace App\Console\Commands\Images;

use App\Services\ImageStore\ObjectStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Prove the object store works the way the edge relies on it: we can write,
 * the store reports the right length, and an ANONYMOUS reader can fetch the
 * object at IMAGE_STORE_PUBLIC_URL.
 *
 * The last part is the one that matters. frontend-nginx asks the bucket for
 * every image the spool no longer holds; a bucket that is not public answers
 * 403, nginx falls through to the legacy share, and every new image 404s with
 * nothing anywhere naming the cause. Run this before enabling the store, and
 * after any change to the bucket or its keys.
 */
class ObjectStoreCheckCommand extends Command
{
    protected $signature = 'images:object-store-check';

    protected $description = 'Write, read back anonymously and delete a probe object in the image store';

    public function handle(): int
    {
        // Built here rather than injected: the container would hand ObjectStore
        // the DEFAULT disk, and the check would pass against local storage.
        $store = new ObjectStore();
        $key = 'probe-' . bin2hex(random_bytes(8));
        $url = $store->publicUrl($key);

        if ($url === null) {
            $this->error('IMAGE_STORE_PUBLIC_URL is not set, so nginx would have nowhere to read from.');

            return Command::FAILURE;
        }

        $body = 'freegle image store probe ' . now()->toIso8601String();

        try {
            $store->put($key, $body, 'text/plain');
        } catch (\Throwable $e) {
            $this->error("Could not write {$key}: " . $e->getMessage());

            return Command::FAILURE;
        }

        try {
            $size = $store->sizeOf($key);
            if ($size !== strlen($body)) {
                $this->error("The store reports {$key} as " . var_export($size, true) . ' bytes, expected ' . strlen($body) . '.');

                return Command::FAILURE;
            }

            $response = Http::timeout(15)->get($url);

            if (! $response->ok()) {
                $this->error("Anonymous GET {$url} returned HTTP {$response->status()}. The bucket is not publicly readable; do not enable the store until it is.");

                return Command::FAILURE;
            }

            if ($response->body() !== $body) {
                $this->error("Anonymous GET {$url} returned different bytes from what was written.");

                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error("Check failed: " . $e->getMessage());

            return Command::FAILURE;
        } finally {
            try {
                $store->delete($key);
            } catch (\Throwable $e) {
                $this->warn("Could not delete the probe {$key}: " . $e->getMessage());
            }
        }

        $this->info("OK: wrote {$key}, the store reports its length, an anonymous GET at {$url} returned it, and it is deleted.");

        return Command::SUCCESS;
    }
}
