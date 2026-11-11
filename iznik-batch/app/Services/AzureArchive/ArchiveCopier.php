<?php

namespace App\Services\AzureArchive;

use App\Services\ImageStore\ImageContentType;
use App\Services\ImageStore\ObjectStore;
use App\Services\ImageStore\ObjectStoreUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Copies archived images from Azure into the images bucket, spending at most the bytes it is
 * given.
 *
 * It walks the source tables by id, never by listing the container. For each archived row it
 * writes the ledger row, fetches the blob once, checks it against Azure's Content-MD5, stores the
 * smallest lossless form under the key prefix, confirms the bucket holds that many bytes, and
 * records the result. A row whose image is served from the uploads store (an externaluid) is
 * marked Superseded and not fetched.
 *
 * It stops at the first sign of trouble that would repeat for every blob: the bucket unavailable,
 * or Azure not answering three times in a row.
 */
class ArchiveCopier
{
    /** Ids read from a source table per query. */
    public const WINDOW = 10000;

    /** Room kept for one more blob: nothing is fetched unless this much of the allowance is left. */
    public const BLOB_RESERVE_BYTES = 2 * 1000 * 1000;

    /** A fetch still marked Fetching after this long belongs to a run that died. */
    private const DEAD_AFTER_MINUTES = 30;

    private const MAX_TRANSIENT_IN_A_ROW = 3;

    /**
     * After this many fetches in a run, a share of missing blobs above MAX_MISSING_SHARE stops it.
     * A 2026-11-11 sample found 7% of posts and 20% of chats missing; nearly all missing means
     * something is wrong with the container, not with the photos.
     */
    private const MISSING_CHECK_AFTER = 200;

    private const MAX_MISSING_SHARE = 0.6;

    /** The allowance for this run is spent: a normal stop. */
    public const STOP_BUDGET = 'budget';

    /** The run reached its time limit: a normal stop, so it never outlasts its lock. */
    public const STOP_TIME = 'time';

    public const STOP_BUCKET = 'bucket';

    /** Azure is not answering properly: probably passing. */
    public const STOP_AZURE = 'azure';

    /** Azure's answers suggest the container itself is wrong: a person must look. */
    public const STOP_SUSPECT = 'suspect';

    /** @var array<string, int> */
    private array $stats;

    private int $spent = 0;

    private int $transientInARow = 0;

    /** @var array{kind: string, message: string}|null */
    private ?array $stopped = null;

    public function __construct(
        private readonly ArchiveLedger $ledger,
        private readonly BlobFetcher $fetcher,
        private readonly ObjectStore $store,
        private readonly ?LosslessJpeg $jpeg,
        private readonly string $keyPrefix,
        // Seconds a run may last; it must stay well inside the copy command's lock.
        private readonly int $maxSeconds = 2400,
    ) {
        $this->resetStats();
    }

    private float $deadline = 0;

    /**
     * @return array{stats: array<string, int>, stopped: array{kind: string, message: string}|null}
     */
    public function copy(int $allowanceBytes): array
    {
        $this->resetStats();
        $this->spent = 0;
        $this->transientInARow = 0;
        $this->stopped = null;
        $this->deadline = microtime(true) + $this->maxSeconds;

        // The bucket is checked before anything is fetched: a fetch whose bytes cannot be stored
        // would have to be fetched again.
        try {
            $this->store->sizeOf($this->keyPrefix . '.probe');
        } catch (ObjectStoreUnavailable $e) {
            return ['stats' => $this->stats, 'stopped' => ['kind' => self::STOP_BUCKET, 'message' => 'The bucket is unavailable: ' . $e->getMessage()]];
        }

        foreach ($this->ledger->retryable(CarbonImmutable::now('UTC')->subMinutes(self::DEAD_AFTER_MINUTES), 1000) as $row) {
            if (! $this->copyOne($row->source, (int) $row->sourceid, $row->blobname, $allowanceBytes, (int) $row->attempts)) {
                break;
            }
        }

        foreach (ArchiveSources::sources() as $source) {
            if ($this->stopped !== null) {
                break;
            }

            $this->walk($source, $allowanceBytes);
        }

        $this->stats['bytes_fetched'] = $this->spent;

        return ['stats' => $this->stats, 'stopped' => $this->stopped];
    }

    /**
     * The next blobs a run would fetch, without fetching or writing anything.
     *
     * @return list<string>
     */
    public function preview(int $limit): array
    {
        $names = [];

        foreach ($this->ledger->retryable(CarbonImmutable::now('UTC')->subMinutes(self::DEAD_AFTER_MINUTES), $limit) as $row) {
            $names[] = $row->blobname . ' (retry)';
        }

        foreach (ArchiveSources::sources() as $source) {
            $cursor = $this->ledger->cursor($source);
            $max = (int) DB::table($source)->max('id');

            while (count($names) < $limit && $cursor < $max) {
                foreach ($this->archivedRows($source, $cursor + 1, $cursor + self::WINDOW) as $row) {
                    if (count($names) >= $limit) {
                        break;
                    }

                    $names[] = ArchiveSources::blobName($source, (int) $row->id) . (empty($row->externaluid) ? '' : ' (superseded)');
                }

                $cursor += self::WINDOW;
            }
        }

        return array_slice($names, 0, $limit);
    }

    private function walk(string $source, int $allowanceBytes): void
    {
        $cursor = $this->ledger->cursor($source);
        $max = (int) DB::table($source)->max('id');

        while ($cursor < $max && $this->stopped === null) {
            $windowEnd = min($cursor + self::WINDOW, $max);

            foreach ($this->archivedRows($source, $cursor + 1, $windowEnd) as $row) {
                $id = (int) $row->id;
                $blob = ArchiveSources::blobName($source, $id);

                if (! empty($row->externaluid)) {
                    $this->ledger->supersede($source, $id, $blob);
                    $this->stats['superseded']++;

                    continue;
                }

                if (! $this->copyOne($source, $id, $blob, $allowanceBytes)) {
                    // Rows before this one have ledger rows, so the walk resumes from here.
                    return;
                }
            }

            $this->ledger->setCursor($source, $windowEnd);
            $cursor = $windowEnd;
        }
    }

    /** @return list<object{id: int, externaluid: ?string}> */
    private function archivedRows(string $source, int $from, int $to): array
    {
        return DB::table($source)
            ->whereBetween('id', [$from, $to])
            ->where('archived', 1)
            ->orderBy('id')
            ->get(['id', 'externaluid'])
            ->all();
    }

    /** Copy one blob. False when the run must stop. */
    private function copyOne(string $source, int $id, string $blob, int $allowanceBytes, ?int $seenAttempts = null): bool
    {
        if ($this->spent + self::BLOB_RESERVE_BYTES > $allowanceBytes) {
            $this->stopped ??= ['kind' => self::STOP_BUDGET, 'message' => 'The egress allowance for this run is spent.'];

            return false;
        }

        if (microtime(true) >= $this->deadline) {
            $this->stopped ??= ['kind' => self::STOP_TIME, 'message' => 'The run reached its time limit.'];

            return false;
        }

        $attempt = $this->ledger->begin($source, $id, $blob, $seenAttempts);

        if ($attempt === null) {
            // Another run holds this row.
            return true;
        }

        $result = $this->fetcher->fetch($blob);
        $this->spent += $result->bytesCounted;
        $this->stats['fetched']++;

        if ($result->transient()) {
            $this->fail($blob, $result->bytesCounted, $attempt, true, 'Azure answered ' . ($result->status ?: 'nothing') . ($result->error ? ": {$result->error}" : ''));

            if (++$this->transientInARow >= self::MAX_TRANSIENT_IN_A_ROW) {
                $this->stopped = ['kind' => self::STOP_AZURE, 'message' => 'Azure did not answer properly ' . self::MAX_TRANSIENT_IN_A_ROW . ' times in a row.'];

                return false;
            }

            return true;
        }

        $this->transientInARow = 0;

        if ($result->status === 404 && $result->errorCode !== 'BlobNotFound') {
            // ContainerNotFound or ResourceNotFound: the container is gone or no longer public,
            // and every blob would answer the same. Recording them Missing would be final.
            $this->fail($blob, $result->bytesCounted, $attempt, true, 'Azure answered 404 ' . ($result->errorCode ?? 'without a code'));
            $this->stopped = ['kind' => self::STOP_SUSPECT, 'message' => 'Azure answered 404 ' . ($result->errorCode ?? 'without a code') . ', not BlobNotFound: the container is not there or not public.'];

            return false;
        }

        if ($result->status === 404) {
            $this->ledger->finish($blob, 'Missing', $result->bytesCounted, ['error' => null]);
            $this->stats['missing']++;

            if ($this->stats['fetched'] >= self::MISSING_CHECK_AFTER && $this->stats['missing'] > self::MAX_MISSING_SHARE * $this->stats['fetched']) {
                $this->stopped = ['kind' => self::STOP_SUSPECT, 'message' => "{$this->stats['missing']} of {$this->stats['fetched']} blobs fetched in this run are missing, far more than expected."];

                return false;
            }

            return true;
        }

        if ($result->status !== 200 || $result->body === null) {
            // 403, or 409 for a blob in the Archive tier, which would cost money to rehydrate.
            $this->fail($blob, $result->bytesCounted, $attempt, false, "Azure answered {$result->status}" . ($result->errorCode ? " ({$result->errorCode})" : ''));

            return true;
        }

        $note = null;

        if ($result->contentMd5 === null) {
            if (! $result->lengthMatched()) {
                $this->fail($blob, $result->bytesCounted, $attempt, true, 'Azure sent no Content-MD5, and the length did not match its Content-Length');

                return true;
            }

            // Fetched and paid for: keep it rather than lose it when Azure goes, and say how it was checked.
            $note = 'Checked by length only: Azure has no Content-MD5 for this blob';
        } elseif (! $result->verified()) {
            $this->fail($blob, $result->bytesCounted, $attempt, true, 'The bytes did not match Azure\'s Content-MD5');

            return true;
        }

        $key = $this->keyPrefix . $blob;

        try {
            $stored = $this->jpeg === null
                ? ['bytes' => $result->body, 'optimised' => false, 'note' => null]
                : $this->jpeg->optimise($result->body);
            $type = LosslessJpeg::isJpeg($stored['bytes']) ? 'image/jpeg' : ImageContentType::detect(substr($stored['bytes'], 0, 512), null);
            $this->store->put($key, $stored['bytes'], $type);
            $size = $this->store->sizeOf($key);
        } catch (ObjectStoreUnavailable $e) {
            $this->fail($blob, $result->bytesCounted, $attempt, true, 'The bucket was unavailable when storing it');
            $this->stopped = ['kind' => self::STOP_BUCKET, 'message' => 'The bucket is unavailable: ' . $e->getMessage()];

            return false;
        } catch (\Throwable $e) {
            // Anything else between the fetch and the bucket: keep the row for one more try
            // rather than leave it Fetching, where it would be stuck.
            $this->fail($blob, $result->bytesCounted, $attempt, true, 'Could not store it: ' . get_class($e) . ': ' . $e->getMessage());

            return true;
        }

        if ($size !== strlen($stored['bytes'])) {
            $this->fail($blob, $result->bytesCounted, $attempt, true, 'The bucket reported ' . var_export($size, true) . ' bytes for ' . strlen($stored['bytes']) . ' written');

            return true;
        }

        $this->ledger->finish($blob, 'Copied', $result->bytesCounted, [
            'azuremd5' => $result->contentMd5,
            'storedbytes' => $size,
            'optimised' => $stored['optimised'],
            'copiedat' => CarbonImmutable::now('UTC'),
            'error' => $note,
        ]);

        $this->stats['copied']++;
        $this->stats['bytes_stored'] += $size;
        $this->stats['bytes_original'] += strlen($result->body);

        return true;
    }

    private function fail(string $blob, int $bytes, int $attempt, bool $retryable, string $why): void
    {
        $again = $retryable && $attempt < ArchiveLedger::MAX_ATTEMPTS;
        $this->ledger->finish($blob, 'Failed', $bytes, ['error' => mb_substr(($again ? ArchiveLedger::RETRY : '') . $why, 0, 255)]);
        $this->stats['failed']++;
    }

    private function resetStats(): void
    {
        $this->stats = [
            'fetched' => 0,
            'copied' => 0,
            'missing' => 0,
            'failed' => 0,
            'superseded' => 0,
            'bytes_fetched' => 0,
            'bytes_original' => 0,
            'bytes_stored' => 0,
        ];
    }
}
