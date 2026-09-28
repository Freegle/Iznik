<?php

namespace App\Services\ImageStore;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Copies every upload the database still refers to from the legacy NFS share
 * into the object store.
 *
 * The share is one flat directory of about two million files and listing it
 * starves tusd (see .claude/rules/dev-containers.md), so this never lists
 * anything. It walks the tables that hold upload ids by primary key, keeps a
 * cursor per source in image_store_migration, and for each id asks the store
 * whether it already holds an object of the right length before reading the
 * file by name. That makes it idempotent, resumable after any stop, and safe
 * to run in short scheduled slices for as many days as the copy takes.
 *
 * It never deletes from the legacy share. The share is retired whole, by hand,
 * once verify() reports nothing missing.
 */
class LegacyMigrationService
{
    public const PREFIX = 'freegletusd-';

    /** source key => [table, column] */
    public const SOURCES = [
        'messages_attachments' => ['messages_attachments', 'externaluid'],
        'chat_images' => ['chat_images', 'externaluid'],
        'users_images' => ['users_images', 'externaluid'],
        'groups_images' => ['groups_images', 'externaluid'],
        'communityevents_images' => ['communityevents_images', 'externaluid'],
        'volunteering_images' => ['volunteering_images', 'externaluid'],
        'newsfeed_images' => ['newsfeed_images', 'externaluid'],
        'users_stories_images' => ['users_stories_images', 'externaluid'],
        'noticeboards_images' => ['noticeboards_images', 'externaluid'],
        'newsletters_images' => ['newsletters_images', 'externaluid'],
        'ai_images' => ['ai_images', 'externaluid'],
        'ai_images:pending_externaluid' => ['ai_images', 'pending_externaluid'],
    ];

    private const ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

    private const MAX_MISSING_IDS = 1000;

    private ?ObjectStore $store;

    private ?Filesystem $legacy;

    /** When the current migrate() began, for the bandwidth cap. */
    private float $runStart = 0.0;

    public function __construct(?ObjectStore $store = null, ?Filesystem $legacy = null)
    {
        $this->store = $store;
        $this->legacy = $legacy;
    }

    protected function store(): ObjectStore
    {
        return $this->store ??= new ObjectStore();
    }

    protected function legacy(): Filesystem
    {
        return $this->legacy ??= Storage::disk('tusd-legacy');
    }

    /**
     * @throws \InvalidArgumentException on a source that is not in SOURCES
     */
    public static function validateSources(array $sources): array
    {
        if ($sources === []) {
            return array_keys(self::SOURCES);
        }

        foreach ($sources as $source) {
            if (! array_key_exists($source, self::SOURCES)) {
                throw new \InvalidArgumentException("Unknown source '{$source}'. Known: " . implode(', ', array_keys(self::SOURCES)));
            }
        }

        return array_values($sources);
    }

    /**
     * Copy what is missing. Stops when the time budget runs out between
     * chunks, when $limit rows have been examined, or when every source is
     * done. Always does at least one chunk, so progress is always possible.
     *
     * @return array{scanned:int,copied:int,present:int,missing_source:int,invalid:int,failed:int,bytes:int,finished:bool,budget_exhausted:bool}
     */
    public function migrate(array $sources, int $timeBudgetSeconds, int $chunk, int $limit = 0, float $maxMbps = 0, bool $dryRun = false): array
    {
        $stats = [
            'scanned' => 0, 'copied' => 0, 'present' => 0, 'missing_source' => 0,
            'invalid' => 0, 'failed' => 0, 'bytes' => 0,
            'finished' => false, 'budget_exhausted' => false,
        ];

        $this->runStart = microtime(true);

        $this->walk($sources, $timeBudgetSeconds, $chunk, $limit, $stats, 'last_id', 'completed_at', $dryRun,
            function (string $uid, array &$stats, array &$row) use ($maxMbps, $dryRun) {
                $this->copyOne($uid, $stats, $row, $maxMbps, $dryRun);
            });

        return $stats;
    }

    /**
     * Re-walk every source and report referenced ids the store lacks. Copies
     * nothing and does not move the copy cursor. Its own cursor lets a long
     * verify be resumed.
     *
     * @return array{scanned:int,present:int,missing:int,missing_ids:list<string>,invalid:int,finished:bool,budget_exhausted:bool}
     */
    public function verify(array $sources, int $timeBudgetSeconds, int $chunk, int $limit = 0): array
    {
        $stats = [
            'scanned' => 0, 'present' => 0, 'missing' => 0, 'missing_ids' => [],
            'invalid' => 0, 'finished' => false, 'budget_exhausted' => false,
        ];

        $this->walk($sources, $timeBudgetSeconds, $chunk, $limit, $stats, 'verify_last_id', 'verify_completed_at', false,
            function (string $uid, array &$stats, array &$row) {
                if ($this->store()->sizeOf($uid) === null) {
                    $stats['missing']++;
                    $row['verify_missing']++;
                    if (count($stats['missing_ids']) < self::MAX_MISSING_IDS) {
                        $stats['missing_ids'][] = $uid;
                    }
                    Log::warning('images:migrate-legacy verify: object missing', ['id' => $uid]);
                } else {
                    $stats['present']++;
                }
            });

        return $stats;
    }

    /**
     * One row per known source, whether or not it has started.
     *
     * @return list<array<string,mixed>>
     */
    public function status(): array
    {
        $rows = DB::table('image_store_migration')->get()->keyBy('source');
        $out = [];

        foreach (array_keys(self::SOURCES) as $source) {
            $row = $rows->get($source);
            $out[] = [
                'source' => $source,
                'last_id' => (int) ($row->last_id ?? 0),
                'copied' => (int) ($row->copied ?? 0),
                'present' => (int) ($row->present ?? 0),
                'missing_source' => (int) ($row->missing_source ?? 0),
                'failed' => (int) ($row->failed ?? 0),
                'bytes' => (int) ($row->bytes ?? 0),
                'verify_last_id' => (int) ($row->verify_last_id ?? 0),
                'verify_missing' => (int) ($row->verify_missing ?? 0),
                'completed_at' => $row->completed_at ?? null,
                'verify_completed_at' => $row->verify_completed_at ?? null,
                'updated_at' => $row->updated_at ?? null,
            ];
        }

        return $out;
    }

    /**
     * Start a source again from the beginning: the copy cursor, or the verify
     * cursor. Counters for that pass are zeroed too, so status stays honest.
     */
    public function resetCursor(string $source, bool $verify = false): void
    {
        self::validateSources([$source]);

        $values = $verify
            ? ['verify_last_id' => 0, 'verify_missing' => 0, 'verify_completed_at' => null]
            : ['last_id' => 0, 'copied' => 0, 'present' => 0, 'missing_source' => 0, 'failed' => 0, 'bytes' => 0, 'completed_at' => null];

        DB::table('image_store_migration')->updateOrInsert(['source' => $source], $values);
    }

    private function walk(array $sources, int $timeBudgetSeconds, int $chunk, int $limit, array &$stats, string $cursorColumn, string $doneColumn, bool $dryRun, callable $each): void
    {
        $sources = self::validateSources($sources);
        $deadline = microtime(true) + max(0, $timeBudgetSeconds);
        $chunk = max(1, $chunk);
        $firstChunk = true;
        $stopped = false;

        foreach ($sources as $source) {
            [$table, $column] = self::SOURCES[$source];
            $row = $this->cursorRow($source);

            if ($row[$doneColumn] !== null) {
                continue;
            }

            $lastId = (int) $row[$cursorColumn];

            while (true) {
                if (! $firstChunk && microtime(true) > $deadline) {
                    $stats['budget_exhausted'] = true;
                    $stopped = true;
                    break;
                }
                $firstChunk = false;

                $rows = DB::table($table)
                    ->where('id', '>', $lastId)
                    ->where($column, 'like', self::PREFIX . '%')
                    ->orderBy('id')
                    ->limit($chunk)
                    ->pluck($column, 'id');

                if ($rows->isEmpty()) {
                    $row[$doneColumn] = now();
                    $this->saveCursor($source, $row, $dryRun);
                    break;
                }

                foreach ($rows as $id => $value) {
                    $lastId = (int) $id;
                    $stats['scanned']++;

                    $uid = substr((string) $value, strlen(self::PREFIX));
                    if ($uid === '' || ! preg_match(self::ID_PATTERN, $uid)) {
                        $stats['invalid']++;
                        Log::warning('images:migrate-legacy: not a tusd id', ['source' => $source, 'id' => $id, 'value' => $value]);
                        continue;
                    }

                    $each($uid, $stats, $row);

                    if ($limit > 0 && $stats['scanned'] >= $limit) {
                        $stopped = true;
                        break;
                    }
                }

                $row[$cursorColumn] = $lastId;
                $this->saveCursor($source, $row, $dryRun);

                if ($stopped) {
                    break;
                }
            }

            if ($stopped) {
                break;
            }
        }

        // Not stopped early means every requested source either was already
        // done or was walked to its last row in this run.
        $stats['finished'] = ! $stopped;
    }

    private function copyOne(string $uid, array &$stats, array &$row, float $maxMbps, bool $dryRun): void
    {
        try {
            $existing = $this->store()->sizeOf($uid);

            if (! $this->legacy()->exists($uid)) {
                $stats['missing_source']++;
                $row['missing_source']++;
                Log::info('images:migrate-legacy: referenced upload not on the legacy share', ['id' => $uid]);

                return;
            }

            $length = $this->legacy()->size($uid);

            if ($existing === $length) {
                $stats['present']++;
                $row['present']++;

                return;
            }

            if ($dryRun) {
                $stats['copied']++;
                $stats['bytes'] += $length;

                return;
            }

            $declared = null;
            if ($this->legacy()->exists($uid . '.info')) {
                $declared = TusInfo::fromJson((string) $this->legacy()->get($uid . '.info'))?->declaredType();
            }

            $stream = $this->legacy()->readStream($uid);
            if (! is_resource($stream)) {
                throw new \RuntimeException('could not open the legacy file');
            }

            try {
                $head = (string) fread($stream, 64);
                rewind($stream);
                $this->store()->put($uid, $stream, ImageContentType::detect($head, $declared));
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $stored = $this->store()->sizeOf($uid);
            if ($stored !== $length) {
                throw new \RuntimeException("stored length {$stored} differs from source {$length}");
            }

            $stats['copied']++;
            $stats['bytes'] += $length;
            $row['copied']++;
            $row['bytes'] += $length;

            $this->pace($stats['bytes'], $this->runStart, $maxMbps);
        } catch (\Throwable $e) {
            $stats['failed']++;
            $row['failed']++;
            Log::warning('images:migrate-legacy: copy failed', ['id' => $uid, 'error' => $e->getMessage()]);
        }
    }

    /** Sleep just enough to keep this run's average under the bandwidth cap. */
    private function pace(int $bytes, float $runStart, float $maxMbps): void
    {
        if ($maxMbps <= 0) {
            return;
        }

        $expected = $bytes / ($maxMbps * 1_000_000);
        $elapsed = microtime(true) - $runStart;

        if ($expected > $elapsed) {
            usleep((int) min(5_000_000, ($expected - $elapsed) * 1_000_000));
        }
    }

    /** @return array<string,mixed> */
    private function cursorRow(string $source): array
    {
        $row = DB::table('image_store_migration')->where('source', $source)->first();

        return [
            'last_id' => (int) ($row->last_id ?? 0),
            'verify_last_id' => (int) ($row->verify_last_id ?? 0),
            'copied' => (int) ($row->copied ?? 0),
            'present' => (int) ($row->present ?? 0),
            'missing_source' => (int) ($row->missing_source ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'bytes' => (int) ($row->bytes ?? 0),
            'verify_missing' => (int) ($row->verify_missing ?? 0),
            'completed_at' => $row->completed_at ?? null,
            'verify_completed_at' => $row->verify_completed_at ?? null,
        ];
    }

    private function saveCursor(string $source, array $row, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        DB::table('image_store_migration')->updateOrInsert(['source' => $source], $row);
    }
}
