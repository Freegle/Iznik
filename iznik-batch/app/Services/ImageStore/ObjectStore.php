<?php

namespace App\Services\ImageStore;

use Aws\S3\Exception\S3Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;

/**
 * The image bucket, through the `images` disk. Small on purpose: the two
 * callers need "how long is this object, if it exists" and "store these bytes
 * as this type", and both must be able to tell "not there" from "the store is
 * broken", because the first means copy and the second means stop.
 */
class ObjectStore
{
    private ?Filesystem $disk;

    public function __construct(?Filesystem $disk = null)
    {
        $this->disk = $disk;
    }

    protected function disk(): Filesystem
    {
        return $this->disk ??= Storage::disk('images');
    }

    /**
     * The object's length, or null when the store has no such key. Any other
     * failure (network, credentials, a 5xx) is rethrown: it must never read as
     * "not there", or the migrator would count a broken store as work to do
     * and verify would report a healthy store as missing everything.
     */
    public function sizeOf(string $key): ?int
    {
        try {
            return $this->disk()->size($key);
        } catch (UnableToRetrieveMetadata $e) {
            $previous = $e->getPrevious();

            if ($previous instanceof S3Exception && $previous->getStatusCode() !== 404) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Store the bytes under the key with the given Content-Type. Objects are
     * immutable (an id is never reused), so they are marked cacheable forever.
     * Throws on failure.
     *
     * @param  resource|string  $contents
     */
    public function put(string $key, $contents, string $contentType): void
    {
        $ok = $this->disk()->put($key, $contents, [
            'ContentType' => $contentType,
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        if ($ok === false) {
            throw new \RuntimeException("Object store refused to write {$key}");
        }
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    /**
     * Where an anonymous reader (frontend-nginx) fetches the key from, or null
     * when no public base URL is configured.
     */
    public function publicUrl(string $key): ?string
    {
        $base = config('filesystems.disks.images.url');

        if (! is_string($base) || $base === '') {
            return null;
        }

        return rtrim($base, '/') . '/' . rawurlencode($key);
    }
}
