<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageIllustrationsService
{
    private const BATCH_SIZE = 5;
    private const CONFIG_KEY = 'illustrations_last_arrival';

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
        ");

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

        return $count;
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
                    ->where('status', 'active')
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

            if (empty($newMessages) && empty($cachedMessages)) {
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

        return trim($name ?? '');
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
