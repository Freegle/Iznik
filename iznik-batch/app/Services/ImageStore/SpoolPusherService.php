<?php

namespace App\Services\ImageStore;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Moves completed uploads from tusd's local spool to the object store.
 *
 * tusd writes every upload as <id> plus <id>.info in the spool and nothing
 * else ever happens to them: tusd has no idea the object store exists. This
 * is what does the moving, one scheduled run a minute, and it is the only
 * thing that deletes from the spool.
 *
 * "Completed" is judged from the file, not from tusd: the bytes on disk are
 * exactly as long as the .info declared, and have not changed for the grace
 * period. A shorter file is an upload still in progress, or one the client
 * walked away from; those are left alone until they are old enough to be
 * called abandoned, and then deleted.
 *
 * The order of the read chain in frontend-nginx (spool, then bucket) is what
 * makes this safe to run at any time: a file is deleted here only after the
 * bucket has confirmed it holds an object of the same length, so there is no
 * moment at which a reader can find neither.
 */
class SpoolPusherService
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

    private const SIDE_FILES = ['.info', '.lock', '.stop'];

    private ?ObjectStore $store;

    private ?Filesystem $spool;

    private int $graceSeconds;

    private int $abandonHours;

    public function __construct(?ObjectStore $store = null, ?Filesystem $spool = null, ?int $graceSeconds = null, ?int $abandonHours = null)
    {
        $this->store = $store;
        $this->spool = $spool;
        $this->graceSeconds = $graceSeconds ?? (int) config('freegle.image_store.push_grace_seconds', 60);
        $this->abandonHours = $abandonHours ?? (int) config('freegle.image_store.abandon_hours', 24);
    }

    protected function store(): ObjectStore
    {
        return $this->store ??= new ObjectStore();
    }

    protected function spool(): Filesystem
    {
        return $this->spool ??= Storage::disk('tusd-spool');
    }

    /**
     * One pass over the spool. $limit bounds the number of uploads pushed or
     * cleaned up in this pass; the rest wait for the next.
     *
     * @return array{scanned:int,pushed:int,present:int,recent:int,incomplete:int,unreadable:int,abandoned:int,failed:int,bytes:int}
     */
    public function push(int $limit = 500, bool $dryRun = false): array
    {
        $stats = [
            'scanned' => 0,
            'pushed' => 0,
            'present' => 0,
            'recent' => 0,
            'incomplete' => 0,
            'unreadable' => 0,
            'abandoned' => 0,
            'failed' => 0,
            'bytes' => 0,
        ];

        $now = time();
        $abandonBefore = $now - $this->abandonHours * 3600;
        $actions = 0;

        $infos = array_filter($this->spool()->files(), fn ($f) => str_ends_with($f, '.info'));
        sort($infos);

        foreach ($infos as $infoPath) {
            if ($limit > 0 && $actions >= $limit) {
                break;
            }

            $id = substr(basename($infoPath), 0, -strlen('.info'));

            if (! preg_match(self::ID_PATTERN, $id)) {
                continue;
            }

            $stats['scanned']++;

            try {
                $result = $this->handle($id, $now, $abandonBefore, $dryRun, $stats);
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::warning('images:push-spool: upload failed', ['id' => $id, 'error' => $e->getMessage()]);
                continue;
            }

            if ($result) {
                $actions++;
            }
        }

        return $stats;
    }

    /**
     * Returns true when the upload was acted on (pushed, found present, or
     * removed as abandoned), which is what the limit counts.
     */
    private function handle(string $id, int $now, int $abandonBefore, bool $dryRun, array &$stats): bool
    {
        $spool = $this->spool();
        $infoPath = $id . '.info';

        $info = TusInfo::fromJson((string) $spool->get($infoPath));
        $hasBytes = $spool->exists($id);
        $newest = $spool->lastModified($infoPath);
        if ($hasBytes) {
            $newest = max($newest, $spool->lastModified($id));
        }

        if ($info === null || ! $hasBytes) {
            // Garbage we cannot verify a length against, or an orphan .info
            // from a terminated upload. Young ones might still be mid-creation.
            if ($newest < $abandonBefore) {
                $stats['abandoned']++;
                if (! $dryRun) {
                    $this->remove($id);
                }

                return true;
            }

            $stats[$info === null ? 'unreadable' : 'incomplete']++;

            return false;
        }

        $length = $spool->size($id);
        $complete = ! $info->sizeIsDeferred && $info->size > 0 && $length === $info->size;

        if (! $complete) {
            if ($newest < $abandonBefore) {
                $stats['abandoned']++;
                if (! $dryRun) {
                    $this->remove($id);
                }

                return true;
            }

            $stats['incomplete']++;

            return false;
        }

        if ($newest > $now - $this->graceSeconds) {
            $stats['recent']++;

            return false;
        }

        if ($dryRun) {
            $stats['pushed']++;
            $stats['bytes'] += $length;

            return true;
        }

        if ($this->store()->sizeOf($id) === $length) {
            // A previous pass died between the upload and the local delete.
            $stats['present']++;
            $this->remove($id);

            return true;
        }

        $stream = $spool->readStream($id);
        if (! is_resource($stream)) {
            throw new \RuntimeException('could not open the spooled bytes');
        }

        try {
            $head = (string) fread($stream, 64);
            rewind($stream);
            $this->store()->put($id, $stream, ImageContentType::detect($head, $info->declaredType()));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $stored = $this->store()->sizeOf($id);
        if ($stored !== $length) {
            // Never delete a local copy the store cannot prove it holds.
            $stats['failed']++;
            Log::warning('images:push-spool: stored length differs', ['id' => $id, 'local' => $length, 'stored' => $stored]);

            return true;
        }

        $this->remove($id);
        $stats['pushed']++;
        $stats['bytes'] += $length;

        return true;
    }

    private function remove(string $id): void
    {
        $spool = $this->spool();
        $spool->delete($id);

        foreach (self::SIDE_FILES as $suffix) {
            $spool->delete($id . $suffix);
        }
    }
}
