<?php

namespace App\Services\AzureArchive;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The azure_archive_copies table, and the two config rows the copy keeps beside it: how far each
 * source table has been walked, and a latched stop.
 *
 * The ledger is also the egress budget's clock. A row is written before its fetch, so a fetch that
 * dies half way still counts: until the real byte count is known it is counted at the worst case.
 */
class ArchiveLedger
{
    public const TABLE = 'azure_archive_copies';

    /** A fetch that has started but not recorded its size is counted as this many bytes. */
    public const UNKNOWN_FETCH_BYTES = 4 * 1000 * 1000;

    /** A blob is fetched at most this many times: once, and one retry. */
    public const MAX_ATTEMPTS = 2;

    /** Failures worth one retry carry this prefix; anything else is final. */
    public const RETRY = 'Retry: ';

    private const CURSOR_KEY = 'azure_archive_cursor';

    private const STOP_KEY = 'azure_archive_stop';

    private const MEASURE_KEY = 'azure_archive_measure';

    /**
     * The last azure-archive:measure: archived rows per source, and when.
     *
     * @return array{at?: string, rows?: array<string, int>, superseded?: array<string, int>}
     */
    public function measurement(): array
    {
        $value = DB::table('config')->where('key', self::MEASURE_KEY)->value('value');
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, int>  $rows
     * @param  array<string, int>  $superseded
     */
    public function saveMeasurement(array $rows, array $superseded): void
    {
        DB::table('config')->upsert([[
            'key' => self::MEASURE_KEY,
            'value' => json_encode(['at' => CarbonImmutable::now('UTC')->toIso8601String(), 'rows' => $rows, 'superseded' => $superseded]),
        ]], ['key'], ['value']);
    }

    /** Bytes the copier fetched since $since, by its own count. */
    public function bytesSince(CarbonImmutable $since): int
    {
        return (int) DB::table(self::TABLE)
            ->where('fetchedat', '>=', $since)
            ->sum(DB::raw('COALESCE(azurebytes, ' . self::UNKNOWN_FETCH_BYTES . ')'));
    }

    /** The highest source id walked for this table: the stored cursor or the ledger, whichever is further. */
    public function cursor(string $source): int
    {
        $stored = (int) ($this->cursors()[$source] ?? 0);
        $ledger = (int) DB::table(self::TABLE)->where('source', $source)->max('sourceid');

        return max($stored, $ledger);
    }

    public function setCursor(string $source, int $id): void
    {
        $cursors = $this->cursors();

        if ((int) ($cursors[$source] ?? 0) >= $id) {
            return;
        }

        $cursors[$source] = $id;
        DB::table('config')->upsert([['key' => self::CURSOR_KEY, 'value' => json_encode($cursors)]], ['key'], ['value']);
    }

    /** @return array<string, int> */
    private function cursors(): array
    {
        $value = DB::table('config')->where('key', self::CURSOR_KEY)->value('value');
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public function latchedStop(): ?string
    {
        $value = DB::table('config')->where('key', self::STOP_KEY)->value('value');

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? ($decoded['reason'] ?? 'stopped') . ' (since ' . ($decoded['at'] ?? '?') . ')' : $value;
    }

    public function latchStop(string $reason): void
    {
        if ($this->latchedStop() !== null) {
            return;
        }

        DB::table('config')->upsert([[
            'key' => self::STOP_KEY,
            'value' => json_encode(['reason' => $reason, 'at' => CarbonImmutable::now('UTC')->toIso8601String()]),
        ]], ['key'], ['value']);
    }

    public function clearStop(): void
    {
        DB::table('config')->where('key', self::STOP_KEY)->delete();
    }

    /** Record that a fetch is starting, and return the attempt number it is. */
    public function begin(string $source, int $sourceId, string $blobName): int
    {
        $now = CarbonImmutable::now('UTC');
        $existing = DB::table(self::TABLE)->where('blobname', $blobName)->first(['id', 'attempts']);

        if ($existing === null) {
            DB::table(self::TABLE)->insert([
                'source' => $source,
                'sourceid' => $sourceId,
                'blobname' => $blobName,
                'status' => 'Fetching',
                'attempts' => 1,
                'fetchedat' => $now,
            ]);

            return 1;
        }

        DB::table(self::TABLE)->where('id', $existing->id)->update([
            'status' => 'Fetching',
            'attempts' => $existing->attempts + 1,
            'fetchedat' => $now,
        ]);

        return $existing->attempts + 1;
    }

    /**
     * Record how a fetch ended. $bytes is what Azure sent this time; it is added to what earlier
     * attempts sent, so the budget sees every byte.
     *
     * @param  array<string, mixed>  $fields
     */
    public function finish(string $blobName, string $status, int $bytes, array $fields = []): void
    {
        DB::table(self::TABLE)->where('blobname', $blobName)->update(array_merge($fields, [
            'status' => $status,
            'azurebytes' => DB::raw('COALESCE(azurebytes, 0) + ' . max(0, $bytes)),
        ]));
    }

    public function supersede(string $source, int $sourceId, string $blobName): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'source' => $source,
            'sourceid' => $sourceId,
            'blobname' => $blobName,
            'status' => 'Superseded',
            'attempts' => 0,
            'error' => 'Has an uploads id, which is served first; the Azure copy is not used.',
        ]);
    }

    /**
     * Rows that may be fetched once more: a fetch that never recorded its end (the process died),
     * or a failure marked for one retry. Never a row already at the attempt limit.
     *
     * @return list<object{source: string, sourceid: int, blobname: string}>
     */
    public function retryable(CarbonImmutable $deadBefore, int $limit): array
    {
        return DB::table(self::TABLE)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(function ($q) use ($deadBefore) {
                $q->where(fn ($q) => $q->where('status', 'Fetching')->where('fetchedat', '<', $deadBefore))
                    ->orWhere(fn ($q) => $q->where('status', 'Failed')->where('error', 'like', self::RETRY . '%'));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['source', 'sourceid', 'blobname'])
            ->all();
    }

    /**
     * Up to $n copied rows of one source, spread at random over the ledger. Each pick is an index
     * seek from a random id, not an ORDER BY RAND() over millions of rows.
     *
     * @return list<object{blobname: string, storedbytes: int}>
     */
    public function sampleCopied(string $source, int $n): array
    {
        $copied = fn () => DB::table(self::TABLE)->where('source', $source)->where('status', 'Copied');
        $min = (int) $copied()->min('id');
        $max = (int) $copied()->max('id');
        $picked = [];

        for ($i = 0; $i < $n * 3 && count($picked) < $n && $max > 0; $i++) {
            $row = $copied()->where('id', '>=', random_int($min, $max))->orderBy('id')->first(['id', 'blobname', 'storedbytes']);

            if ($row !== null) {
                $picked[$row->id] = $row;
            }
        }

        return array_values($picked);
    }

    /**
     * Progress by source and status: rows, bytes fetched, bytes stored.
     *
     * @return list<object{source: string, status: string, n: int, bytesfetched: int, bytesstored: int}>
     */
    public function progress(): array
    {
        return DB::table(self::TABLE)
            ->select('source', 'status', DB::raw('COUNT(*) AS n'), DB::raw('COALESCE(SUM(azurebytes), 0) AS bytesfetched'), DB::raw('COALESCE(SUM(storedbytes), 0) AS bytesstored'))
            ->groupBy('source', 'status')
            ->orderBy('source')
            ->orderBy('status')
            ->get()
            ->all();
    }
}
