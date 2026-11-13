<?php

namespace App\Services\AzureArchive;

use App\Database\Expressions\Arithmetic;
use App\Database\Expressions\CastAs;
use App\Database\Expressions\Coalesce;
use App\Database\Expressions\Greatest;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The azure_archive_copies table, and the two config rows the copy keeps beside it: how far each
 * source table has been walked, and a latched stop.
 *
 * The ledger is also the egress budget's clock. Starting a fetch adds the worst case to the row
 * (UNKNOWN_FETCH_BYTES) and finishing it swaps that for the real count, so a fetch the process
 * died in stays counted at the worst case, for ever. Starting a fetch is also a claim: a second
 * run that tries the same row at the same moment is turned away.
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

    private const COST_ACK_KEY = 'azure_archive_cost_ack';

    /** Charges closer than this are the same charge. */
    private const COST_EPSILON = 0.005;

    /**
     * An acknowledgement whose figures were last seen longer ago than a billing period has lost
     * track of which period it belongs to, so it is dropped and every charge latches again.
     */
    private const COST_ACK_DAYS = 31;

    /**
     * The bandwidth charges a person has acknowledged by clearing a stop, for this billing period
     * and the last, and the figures last seen. A charge latches a stop only when it is above what
     * was acknowledged.
     *
     * @return array{current: float, previous: float, lastCurrent: ?float, lastPrevious: ?float}
     */
    public function costAcknowledgement(): array
    {
        $value = DB::table('config')->where('key', self::COST_ACK_KEY)->value('value');
        $d = is_string($value) ? json_decode($value, true) : null;
        $d = is_array($d) ? $d : [];

        if (! isset($d['seenAt']) || CarbonImmutable::parse($d['seenAt'])->lt(CarbonImmutable::now('UTC')->subDays(self::COST_ACK_DAYS))) {
            $d = [];
        }

        return [
            'current' => (float) ($d['current'] ?? 0),
            'previous' => (float) ($d['previous'] ?? 0),
            'lastCurrent' => isset($d['lastCurrent']) ? (float) $d['lastCurrent'] : null,
            'lastPrevious' => isset($d['lastPrevious']) ? (float) $d['lastPrevious'] : null,
        ];
    }

    /**
     * Record the charges Azure reports now. The billing period has rolled over when this
     * period's charge falls, or last period's changes: what was acknowledged for this period then
     * belongs to the last one, and nothing is acknowledged for the new one. A late charge that
     * changes last period's figure is read the same way, which can only latch a stop, never clear one.
     */
    public function noteCosts(float $current, ?float $previous): void
    {
        $ack = $this->costAcknowledgement();
        $rolled = ($ack['lastCurrent'] !== null && $current < $ack['lastCurrent'] - self::COST_EPSILON)
            || ($previous !== null && $ack['lastPrevious'] !== null && abs($previous - $ack['lastPrevious']) > self::COST_EPSILON);

        if ($rolled) {
            $ack['previous'] = $ack['current'];
            $ack['current'] = 0.0;
        }

        $ack['lastCurrent'] = $current;
        $ack['lastPrevious'] = $previous ?? $ack['lastPrevious'];
        $this->saveCostAcknowledgement($ack);
    }

    /** A person has looked at these charges: they no longer latch a stop. */
    public function acknowledgeCosts(float $current, float $previous): void
    {
        $this->saveCostAcknowledgement(['current' => $current, 'previous' => $previous, 'lastCurrent' => $current, 'lastPrevious' => $previous]);
    }

    /** @param  array{current: float, previous: float, lastCurrent: ?float, lastPrevious: ?float}  $ack */
    private function saveCostAcknowledgement(array $ack): void
    {
        $ack['seenAt'] = CarbonImmutable::now('UTC')->toIso8601String();
        DB::table('config')->upsert([['key' => self::COST_ACK_KEY, 'value' => json_encode($ack)]], ['key'], ['value']);
    }

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

    /** Bytes the copier fetched from $from up to (not including) $to. */
    public function bytesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) DB::table(self::TABLE)
            ->where('fetchedat', '>=', $from)
            ->where('fetchedat', '<', $to)
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

    /** Latch a stop, or add a new reason to one already latched, so a person sees every reason. */
    public function latchStop(string $reason): void
    {
        $value = DB::table('config')->where('key', self::STOP_KEY)->value('value');
        $current = is_string($value) ? json_decode($value, true) : null;

        if (is_array($current) && str_contains((string) ($current['reason'] ?? ''), $reason)) {
            return;
        }

        $stop = is_array($current)
            ? ['reason' => mb_substr($current['reason'] . '; and ' . $reason, 0, 2000), 'at' => $current['at'] ?? CarbonImmutable::now('UTC')->toIso8601String()]
            : ['reason' => $reason, 'at' => CarbonImmutable::now('UTC')->toIso8601String()];

        DB::table('config')->upsert([['key' => self::STOP_KEY, 'value' => json_encode($stop)]], ['key'], ['value']);
    }

    public function clearStop(): void
    {
        DB::table('config')->where('key', self::STOP_KEY)->delete();
    }

    /**
     * Claim the row and record that a fetch is starting: the worst case is added to its bytes
     * until finish() settles them. Returns the attempt number, or null when another run holds the
     * row, in which case nothing may be fetched. Only a retry may fetch a row the ledger already
     * has, and it passes the attempts it saw when it chose the row, so a run that chose it at the
     * same moment finds the count moved on and is refused.
     */
    public function begin(string $source, int $sourceId, string $blobName, ?int $seenAttempts = null): ?int
    {
        $now = CarbonImmutable::now('UTC');
        $existing = DB::table(self::TABLE)->where('blobname', $blobName)->first(['id', 'attempts']);

        if ($existing === null) {
            try {
                DB::table(self::TABLE)->insert([
                    'source' => $source,
                    'sourceid' => $sourceId,
                    'blobname' => $blobName,
                    'status' => 'Fetching',
                    'attempts' => 1,
                    'azurebytes' => self::UNKNOWN_FETCH_BYTES,
                    'fetchedat' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                return null;
            }

            return 1;
        }

        $seen = $seenAttempts;

        if ($seen === null || $seen >= self::MAX_ATTEMPTS) {
            return null;
        }

        $claimed = DB::table(self::TABLE)
            ->where('id', $existing->id)
            ->where('attempts', $seen)
            ->update([
                'status' => 'Fetching',
                'attempts' => $existing->attempts + 1,
                'azurebytes' => DB::raw('COALESCE(azurebytes, 0) + ' . self::UNKNOWN_FETCH_BYTES),
                'fetchedat' => $now,
            ]);

        return $claimed === 1 ? $seen + 1 : null;
    }

    /**
     * Record how a fetch ended: the worst case begin() added is swapped for $bytes, what Azure
     * sent this time. Earlier attempts stay counted, so the budget sees every byte.
     *
     * @param  array<string, mixed>  $fields
     */
    public function finish(string $blobName, string $status, int $bytes, array $fields = []): void
    {
        DB::table(self::TABLE)->where('blobname', $blobName)->update(array_merge($fields, [
            'status' => $status,
            'azurebytes' => new Arithmetic(
                new Greatest(new Arithmetic(new CastAs(new Coalesce('azurebytes', 0), 'SIGNED'), '-', self::UNKNOWN_FETCH_BYTES), 0),
                '+',
                max(0, $bytes)
            ),
        ]));
    }

    /**
     * Give rows a person has looked at one more fetch: every final failure, whatever its attempts,
     * and fetches the process died in twice. Returns how many.
     */
    public function requeue(): int
    {
        return DB::table(self::TABLE)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('status', 'Failed')->where(fn ($q) => $q->whereNull('error')->orWhere('error', 'not like', self::RETRY . '%')))
                // A fetch that may still be running is left alone.
                ->orWhere(fn ($q) => $q->where('status', 'Fetching')->where('attempts', '>=', self::MAX_ATTEMPTS)->where('fetchedat', '<', CarbonImmutable::now('UTC')->subHour())))
            ->update([
                'status' => 'Failed',
                'attempts' => DB::raw('LEAST(attempts, ' . (self::MAX_ATTEMPTS - 1) . ')'),
                'error' => DB::raw("LEFT(CONCAT('" . self::RETRY . "requeued by hand: ', COALESCE(error, 'died while fetching')), 255)"),
            ]);
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
     * @return list<object{source: string, sourceid: int, blobname: string, attempts: int}>
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
            ->get(['source', 'sourceid', 'blobname', 'attempts'])
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
