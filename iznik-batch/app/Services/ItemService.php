<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Item catalog helper: find-or-create item rows, link them to messages, and
 * estimate weights. Ported from V1 iznik-server include/message/Item.php
 * (create/estimateWeight) and the item-extraction in Message::save().
 *
 * Both the incoming-mail path and the messages:backfill-items command use this
 * so that every OFFER/WANTED message has a messages_items row — without it the
 * Weight stat's INNER JOIN messages_items silently drops the message.
 */
class ItemService
{
    /** Matches V1 Item::MAX_ITEM_NAME_LENGTH. */
    public const MAX_ITEM_NAME_LENGTH = 60;

    /** Memoised standard weights so a batch backfill scans the table once. */
    private ?array $weights = null;

    /**
     * Extract the item name from a well-formed "TYPE: item (location)" subject.
     * Mirrors the regex V1 Message::save() used to decide whether to record an item.
     */
    public function extractItemName(string $subject): ?string
    {
        if (preg_match('/.*?\:(.*)\(.*\)/', $subject, $matches)) {
            $name = trim($matches[1]);

            return $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * Find or create the catalog row for $name, returning its id (null for an
     * empty name). The `name` unique index is case-insensitive, so the
     * LAST_INSERT_ID() upsert returns the existing id on a case-only difference
     * — exactly as V1 Item::create() did. A freshly-created item gets a weight
     * estimate; existing rows keep their weight.
     */
    public function findOrCreate(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        if (mb_strlen($name) > self::MAX_ITEM_NAME_LENGTH) {
            $name = mb_substr($name, 0, self::MAX_ITEM_NAME_LENGTH);
        }

        DB::statement(
            'INSERT INTO items (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name = ?',
            [$name, $name]
        );
        $id = (int) DB::getPdo()->lastInsertId();
        if ($id <= 0) {
            return null;
        }

        // Only estimate when the row has no weight yet (new item, or an old one
        // we've never weighed). Avoids reweighing established catalog entries.
        $current = DB::table('items')->where('id', $id)->value('weight');
        if ($current === null) {
            $weight = $this->estimateWeight($name);
            if ($weight !== null) {
                DB::table('items')->where('id', $id)->update(['weight' => $weight]);
            }
        }

        return $id;
    }

    /**
     * Link a message to an item. Idempotent (INSERT IGNORE on the composite key),
     * matching V1 Message::addItem().
     */
    public function linkToMessage(int $msgid, int $itemid): void
    {
        DB::statement(
            'INSERT IGNORE INTO messages_items (msgid, itemid) VALUES (?, ?)',
            [$msgid, $itemid]
        );
    }

    /**
     * Convenience: extract the item from a subject, find/create it, and link it
     * to the message. Returns the item id, or null if the subject is not a
     * well-formed OFFER/WANTED subject.
     */
    public function recordFromSubject(int $msgid, string $subject): ?int
    {
        $name = $this->extractItemName($subject);
        if ($name === null) {
            return null;
        }

        $itemid = $this->findOrCreate($name);
        if ($itemid === null) {
            return null;
        }

        $this->linkToMessage($msgid, $itemid);

        return $itemid;
    }

    /**
     * Estimate an item's weight from the standard `weights` table, picking the
     * entry with the most words in common. Returns null when nothing is a good
     * enough match (V1 threshold: words-in-common > 10%). Ported from
     * V1 Item::estimateWeight().
     */
    public function estimateWeight(string $name): ?float
    {
        $bestWeight = null;
        $bestWic = null;

        foreach ($this->standardWeights() as $weight) {
            $wic = $this->wordsInCommon($name, $weight['name']);
            if ($bestWic === null || $wic > $bestWic) {
                $bestWic = $wic;
                $bestWeight = $weight['weight'];
            }
        }

        return ($bestWic !== null && $bestWic > 10) ? (float) $bestWeight : null;
    }

    /**
     * @return array<int,array{name:string,weight:float}>
     */
    private function standardWeights(): array
    {
        if ($this->weights === null) {
            $this->weights = DB::table('weights')
                ->selectRaw('CASE WHEN simplename IS NOT NULL THEN simplename ELSE name END AS name, weight')
                ->get()
                ->map(fn ($row) => ['name' => (string) $row->name, 'weight' => (float) $row->weight])
                ->all();
        }

        return $this->weights;
    }

    // --- Word-similarity helpers, ported verbatim from V1 Utils ---

    private function wordsInCommon(string $sentence1, string $sentence2): float
    {
        $words1 = $this->canonSentence($sentence1);
        $words2 = $this->canonSentence($sentence2);

        $ret = 0;
        foreach ($words1 as $w1) {
            foreach ($words2 as $w2) {
                if ($w1 === $w2) {
                    $ret++;
                }
            }
        }

        $limit = max(count($words1), count($words2));

        return $limit === 1 ? 0 : (100 * $ret / $limit);
    }

    /**
     * @return array<int,string>
     */
    private function canonSentence(string $sentence): array
    {
        $words = preg_split('/\s+/', $sentence) ?: [];
        $canon = array_map(fn ($w) => $this->canonWord($w), $words);

        return array_values(array_unique($canon));
    }

    private function canonWord(string $word): string
    {
        $word = strtolower($word);
        $word = preg_replace('/[^\da-z]/i', '', $word) ?? '';

        if (strlen($word) > 3) {
            $arr = str_split($word);
            sort($arr);
            $word = implode('', $arr);
        }

        return $word;
    }
}
