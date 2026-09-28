<?php

namespace App\Services\ImageStore;

use Aws\Exception\AwsException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
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
     * failure (credentials, a 5xx, no answer) is ObjectStoreUnavailable: it
     * must never read as "not there", or the migrator would count a broken
     * store as work to do and verify would report a healthy store as missing
     * everything.
     */
    public function sizeOf(string $key): ?int
    {
        try {
            return $this->disk()->size($key);
        } catch (UnableToRetrieveMetadata $e) {
            if (self::statusCode($e) === 404) {
                return null;
            }

            throw self::unavailable('HeadObject', $key, $e);
        }
    }

    /**
     * Store the bytes under the key with the given Content-Type. Objects are
     * immutable (an id is never reused), so they are marked cacheable forever.
     * Throws ObjectStoreUnavailable when the store refused or did not answer,
     * and the underlying exception for anything specific to this object.
     *
     * @param  resource|string  $contents
     */
    public function put(string $key, $contents, string $contentType): void
    {
        try {
            $ok = $this->disk()->put($key, $contents, [
                'ContentType' => $contentType,
                'CacheControl' => 'public, max-age=31536000, immutable',
            ]);
        } catch (FilesystemException $e) {
            $status = self::statusCode($e);

            if ($status === null || $status === 401 || $status === 403 || $status >= 500) {
                throw self::unavailable('PutObject', $key, $e);
            }

            throw $e;
        }

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

    /**
     * The HTTP status the store answered with, or null when it did not answer
     * (a connection failure has no response) or the failure was not the
     * store's at all.
     */
    private static function statusCode(\Throwable $e): ?int
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof AwsException) {
                return $cause->getStatusCode();
            }
        }

        return null;
    }

    private static function unavailable(string $operation, string $key, \Throwable $e): ObjectStoreUnavailable
    {
        $status = self::statusCode($e);
        $answer = $status === null ? 'no answer' : "HTTP {$status}";

        return new ObjectStoreUnavailable(
            "Object store unavailable: {$operation} {$key} got {$answer}: " . $e->getMessage(),
            $status ?? 0,
            $e
        );
    }
}
