<?php

namespace App\Services;

use App\Support\ItemName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageIllustrationsService
{
    private const BATCH_SIZE = 5;
    private const CONFIG_KEY = 'illustrations_last_arrival';
    private const CLEANUP_WATERMARK_KEY = 'illustrations_cleanup_last_id';

    // How long we keep holding the saved position for a post that is still waiting to be
    // approved. Long enough for ordinary moderation, short enough that an abandoned post in
    // a queue cannot stall the job indefinitely.
    private const WAITING_WINDOW_DAYS = 3;

    // Most passes a single run will make. Holding the saved position back means a run starts
    // at the oldest post still waiting, so without a cap one run could try to work through
    // days of backlog in a single go and outlast the 15 minute overlap lock.
    private const MAX_PASSES = 6;

    // How long a run may keep going. The job is scheduled every minute and will not overlap
    // itself for 15, so a run that keeps finding work must still hand back in good time
    // rather than swallow a quarter of an hour of ticks.
    private const MAX_RUN_SECONDS = 300;

    public function __construct(private PollinationsService $pollinations) {}

    /**
     * Generate AI illustrations for messages that have no attachments.
     *
     * @return array{cleaned: int, processed: int, would_fetch: int, cached_hits: int}
     */
    public function processIllustrations(bool $dryRun = false): array
    {
        $cleaned = $this->cleanupDuplicates($dryRun);
        $batchStats = $this->processBatches($dryRun);

        return ['cleaned' => $cleaned] + $batchStats;
    }

    /**
     * Remove AI illustrations from messages where the user has since added their own photo.
     */
    private function cleanupDuplicates(bool $dryRun = false): int
    {
        // This runs every minute and, in the steady state, returns nothing - the historical
        // backlog is long clear. Unbounded it still drove a full scan of messages_attachments
        // (39.6M rows in production, 20% of the database's overnight CPU) to find that out.
        //
        // Two branches rather than one OR: each is a primary-key range scan, whereas an OR
        // across the two aliases would put the optimiser back on a full scan.
        //
        // Both sides are watermarked because either can arrive last. Usually it is the member's
        // photo, landing after the illustration. But generation races the upload, so an
        // illustration can also be written after the photo - and a photo-side-only watermark
        // would then never see the pair again, leaving the illustration in place for good.
        $watermark = $this->getCleanupWatermark();

        // Read the high-water mark BEFORE the query, so anything inserted while it runs falls
        // above the mark and is picked up next time rather than skipped.
        $highWater = (int) (DB::table('messages_attachments')->max('id') ?? 0);

        $duplicates = DB::select("
            SELECT DISTINCT ma_ai.id, ma_ai.msgid
            FROM messages_attachments ma_ai
            INNER JOIN messages_attachments ma_real ON ma_real.msgid = ma_ai.msgid
            WHERE JSON_EXTRACT(ma_ai.externalmods, '$.ai') = TRUE
            AND (
                ma_real.externalmods IS NULL
                OR JSON_EXTRACT(ma_real.externalmods, '$.ai') IS NULL
                OR JSON_EXTRACT(ma_real.externalmods, '$.ai') = FALSE
            )
            AND ma_real.id > ?
            UNION
            SELECT DISTINCT ma_ai.id, ma_ai.msgid
            FROM messages_attachments ma_ai
            INNER JOIN messages_attachments ma_real ON ma_real.msgid = ma_ai.msgid
            WHERE JSON_EXTRACT(ma_ai.externalmods, '$.ai') = TRUE
            AND (
                ma_real.externalmods IS NULL
                OR JSON_EXTRACT(ma_real.externalmods, '$.ai') IS NULL
                OR JSON_EXTRACT(ma_real.externalmods, '$.ai') = FALSE
            )
            AND ma_ai.id > ?
        ", [$watermark, $watermark]);

        $count = 0;
        foreach ($duplicates as $dup) {
            if (!$dryRun) {
                DB::table('messages_attachments')->where('id', $dup->id)->delete();

                $hasPrimary = DB::table('messages_attachments')
                    ->where('msgid', $dup->msgid)
                    ->where('primary', 1)
                    ->exists();

                if (! $hasPrimary) {
                    DB::statement(
                        'UPDATE messages_attachments SET `primary` = 1 WHERE msgid = ? ORDER BY id ASC LIMIT 1',
                        [$dup->msgid]
                    );
                }
            }
            $count++;
        }

        if (! $dryRun) {
            $this->setCleanupWatermark($highWater);
        }

        return $count;
    }

    private function getCleanupWatermark(): int
    {
        return (int) (DB::table('config')->where('key', self::CLEANUP_WATERMARK_KEY)->value('value') ?? 0);
    }

    private function setCleanupWatermark(int $id): void
    {
        DB::table('config')->upsert(
            ['key' => self::CLEANUP_WATERMARK_KEY, 'value' => (string) $id],
            ['key'],
            ['value']
        );
    }

    private function processBatches(bool $dryRun = false): array
    {
        $lastArrival = $this->getLastArrival();
        $processed = 0;
        $wouldFetch = 0;
        $cachedHits = 0;
        // Earliest arrival this run still owes work on, across all its passes. Once set
        // it only moves earlier, so a later clean pass cannot save a watermark past it.
        $pinned = null;

        $passes = 0;
        $startedAt = microtime(true);

        while (true) {
            if (++$passes > self::MAX_PASSES) {
                break;
            }

            if (microtime(true) - $startedAt > self::MAX_RUN_SECONDS) {
                Log::info('MessageIllustrations: out of time for this run, the next one carries on');
                break;
            }

            $passStart = $lastArrival;
            $msgs = DB::select("
                SELECT DISTINCT mg.msgid, m.subject, mg.arrival
                FROM messages_groups mg
                INNER JOIN messages m ON m.id = mg.msgid
                INNER JOIN messages_spatial ms ON ms.msgid = mg.msgid
                LEFT JOIN messages_attachments ma ON ma.msgid = m.id
                LEFT JOIN messages_ai_declined maid ON maid.msgid = m.id
                WHERE mg.arrival >= ?
                AND mg.collection IN ('Approved', 'Pending')
                AND ma.id IS NULL
                AND maid.msgid IS NULL
                AND m.subject IS NOT NULL
                AND m.subject != ''
                ORDER BY mg.arrival ASC, mg.msgid ASC
                LIMIT ?
            ", [$lastArrival, self::BATCH_SIZE * 2]);

            if (empty($msgs)) {
                break;
            }

            $cachedMessages = [];
            $newMessages = [];
            $maxArrival = $lastArrival;
            $createdThisPass = 0;
            $unresolved = [];

            foreach ($msgs as $msg) {
                $arrival = $msg->arrival;
                if ($arrival > $maxArrival) {
                    $maxArrival = $arrival;
                }

                $itemName = $this->extractItemName($msg->subject);
                if ($itemName === '') {
                    continue;
                }

                if ($this->pollinations->shouldSkipItem($itemName)) {
                    Log::info("MessageIllustrations: skipping '{$itemName}' due to previous failures");
                    continue;
                }

                $cached = DB::table('ai_images')
                    ->where('name', $itemName)
                    ->whereNotNull('externaluid')
                    ->value('externaluid');

                if ($cached) {
                    $cachedMessages[] = ['msgid' => $msg->msgid, 'itemName' => $itemName, 'uid' => $cached, 'arrival' => $arrival];
                } elseif (count($newMessages) < self::BATCH_SIZE) {
                    $newMessages[] = ['msgid' => $msg->msgid, 'itemName' => $itemName, 'arrival' => $arrival];
                } else {
                    // Past this pass's batch. It is a candidate we have not dealt with,
                    // so the saved watermark must not move beyond it.
                    $unresolved[] = $arrival;
                }
            }

            foreach ($cachedMessages as $cached) {
                $hasAttachment = DB::table('messages_attachments')->where('msgid', $cached['msgid'])->exists();
                if (! $hasAttachment) {
                    if (!$dryRun) {
                        DB::table('messages_attachments')->insert([
                            'msgid' => $cached['msgid'],
                            'externaluid' => $cached['uid'],
                            'externalmods' => json_encode(['ai' => true]),
                            'contenttype' => 'image/jpeg',
                        ]);
                        $processed++;
                        $createdThisPass++;
                        Log::info("MessageIllustrations: used cached illustration for message {$cached['msgid']}: {$cached['itemName']}");
                    }
                    $cachedHits++;
                }
            }

            if (! empty($newMessages)) {
                if ($dryRun) {
                    // Don't call pollinations.ai (costs $) on dry-run; just count.
                    $wouldFetch += count($newMessages);
                    foreach ($newMessages as $msg) {
                        Log::info("MessageIllustrations dry-run: would fetch '{$msg['itemName']}' for message {$msg['msgid']}");
                    }
                    // Stop after one batch in dry-run; we have enough info.
                    break;
                }

                $batchItems = [];
                foreach ($newMessages as $msg) {
                    $batchItems[] = [
                        'name' => $msg['itemName'],
                        'prompt' => $this->pollinations->buildMessagePrompt($msg['itemName']),
                        'width' => 640,
                        'height' => 480,
                        'msgid' => $msg['msgid'],
                    ];
                }

                $batchResult = $this->pollinations->fetchBatch($batchItems, 120);

                if ($batchResult === false) {
                    foreach ($batchItems as $item) {
                        $this->pollinations->recordFailure($item['name']);
                    }
                    Log::warning('MessageIllustrations: batch rate-limited');
                    break;
                }

                foreach ($batchResult['failed'] as $failedName => $dummy) {
                    $this->pollinations->recordFailure($failedName);
                }

                foreach ($batchResult['results'] as $result) {
                    $msgid = $result['msgid'];
                    $itemName = $result['name'];
                    $imageData = $result['data'];
                    $hash = $result['hash'];

                    $hasAttachment = DB::table('messages_attachments')->where('msgid', $msgid)->exists();
                    if ($hasAttachment) {
                        continue;
                    }

                    $uid = $this->pollinations->uploadImageAndCache($itemName, $imageData, $hash);
                    // A failed store is deliberately NOT recorded as a failure of this item.
                    // Storage failing is a system problem, not something wrong with this
                    // picture: when the image server is unreachable every item fails, so
                    // recording it would park them all for a day (three strikes, then
                    // FAILED_CACHE_EXPIRY) and they would still have no picture long after
                    // storage came back. Stalling on the first one is the better failure:
                    // on 2026-09-18 an NFS lock stalled the job for 2h20m, and the moment
                    // the lock cleared the blocked items were stored and the job moved on.
                    if ($uid) {
                        DB::table('messages_attachments')->insert([
                            'msgid' => $msgid,
                            'externaluid' => $uid,
                            'externalmods' => json_encode(['ai' => true]),
                            'contenttype' => 'image/jpeg',
                        ]);
                        $processed++;
                        $createdThisPass++;
                        Log::info("MessageIllustrations: created illustration for message {$msgid}: {$itemName}");
                    }
                }
            }

            // Anything we set out to illustrate and did not still needs doing, so the
            // SAVED watermark must not move past it. Discourse 9630/70: the watermark
            // advanced to the highest arrival INSPECTED, so a message whose generation
            // failed was excluded from every future run the moment a later-arriving
            // message in the same pass succeeded. It could never be retried.
            $attempted = array_merge($cachedMessages, $newMessages);
            if (! empty($attempted)) {
                $illustrated = DB::table('messages_attachments')
                    ->whereIn('msgid', array_column($attempted, 'msgid'))
                    ->pluck('msgid')
                    ->all();
                foreach ($attempted as $a) {
                    if (! in_array($a['msgid'], $illustrated)) {
                        $unresolved[] = $a['arrival'];
                    }
                }
            }
            if (! empty($unresolved)) {
                $earliest = min($unresolved);
                if ($pinned === null || $earliest < $pinned) {
                    $pinned = $earliest;
                }
            }

            // A post is only a candidate once it is approved, because the query above needs a
            // row in the spatial index and that holds approved posts. The watermark moves on
            // arrival time regardless, so a post sitting in a moderation queue while the sweep
            // goes past its arrival is never looked at again, and gets no illustration even
            // after a moderator approves it. Discourse 9630/97 is two such posts. Hold the
            // saved mark at the oldest post still waiting, so approval is not too late.
            $waiting = $this->oldestWaitingForApproval();
            if ($waiting !== null && ($pinned === null || $waiting < $pinned)) {
                $pinned = $waiting;
            }

            // The cursor still sweeps forward, so this run does not re-read what it has
            // just tried: an immediate retry of a failure that is probably rate limiting
            // only spends the next call. What we SAVE is the earliest point still owed
            // work, which is where the next run picks up.
            if ($maxArrival > $lastArrival) {
                $lastArrival = $maxArrival;
            }
            if (!$dryRun) {
                $this->setLastArrival($pinned ?? $lastArrival);
            }

            // The candidate query is inclusive of $lastArrival, so a pass that cannot move the
            // cursor on would see exactly the same rows again: stop, rather than re-run the same
            // query until MySQL kills it at 30s. Covers the empty-batch case too.
            //
            // The test used to be "this pass attached nothing", which is not the same thing. A
            // pass whose candidates were all parked after earlier failures attaches nothing while
            // still having moved on, and stopping there left everything newer unillustrated. That
            // matters more now the saved position is held back to the oldest post still waiting
            // for a moderator, because a run starts among the older posts rather than today's.
            if ($lastArrival <= $passStart) {
                break;
            }

            // A dry run is asking what one pass would do, not working through the backlog.
            if ($dryRun) {
                break;
            }
        }

        return [
            'processed' => $processed,
            'cached_hits' => $cachedHits,
            'would_fetch' => $wouldFetch,
        ];
    }

    private function extractItemName(string $subject): string
    {
        $name = preg_replace('/^(OFFER|WANTED|TAKEN|RECEIVED):\s*/i', '', $subject);
        $name = preg_replace('/\s*\([^)]+\)\s*$/', '', $name ?? '');

        // "iron please" is a request for an iron, not for an "iron please" - and the clean
        // name is what finds the illustration we have already generated for one.
        return ItemName::stripCourtesy(trim($name ?? ''));
    }

    /**
     * Arrival of the oldest post still waiting for a moderator, within the window we would
     * still illustrate. Null when there is none.
     *
     * Bounded by WAITING_WINDOW_DAYS so one post left in a moderation queue for ever cannot
     * hold the whole job at its arrival for ever: past that age we give up on it, which is
     * the same thing the run already does with a post it cannot illustrate.
     */
    private function oldestWaitingForApproval(): ?string
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::WAITING_WINDOW_DAYS . ' days'));

        $row = DB::selectOne("
            SELECT MIN(mg.arrival) AS arrival
            FROM messages_groups mg
            INNER JOIN messages m ON m.id = mg.msgid
            LEFT JOIN messages_attachments ma ON ma.msgid = m.id
            LEFT JOIN messages_ai_declined maid ON maid.msgid = m.id
            WHERE mg.collection = 'Pending'
            AND mg.arrival >= ?
            AND ma.id IS NULL
            AND maid.msgid IS NULL
            AND m.subject IS NOT NULL
            AND m.subject != ''
        ", [$cutoff]);

        return $row?->arrival;
    }

    private function getLastArrival(): string
    {
        $value = DB::table('config')->where('key', self::CONFIG_KEY)->value('value');

        return $value ?? date('Y-m-d H:i:s', strtotime('-1 day'));
    }

    private function setLastArrival(string $arrival): void
    {
        DB::table('config')->upsert(
            ['key' => self::CONFIG_KEY, 'value' => $arrival],
            ['key'],
            ['value']
        );
    }
}
