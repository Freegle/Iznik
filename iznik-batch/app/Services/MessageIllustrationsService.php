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

        while (true) {
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
                    $cachedMessages[] = ['msgid' => $msg->msgid, 'itemName' => $itemName, 'uid' => $cached];
                } elseif (count($newMessages) < self::BATCH_SIZE) {
                    $newMessages[] = ['msgid' => $msg->msgid, 'itemName' => $itemName];
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

            if ($maxArrival > $lastArrival) {
                $lastArrival = $maxArrival;
                if (!$dryRun) {
                    $this->setLastArrival($lastArrival);
                }
            }

            // The candidate query is inclusive of $lastArrival and selects on the absence of an
            // attachment, so a message we failed to illustrate comes back in the next pass
            // unchanged. If a pass attaches nothing, the next one would see exactly the same rows:
            // stop, rather than re-run the same query until MySQL kills it at 30s. Covers the
            // empty-batch case too, and stops a dry run after one pass, which is what it wants.
            if ($createdThisPass === 0) {
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
