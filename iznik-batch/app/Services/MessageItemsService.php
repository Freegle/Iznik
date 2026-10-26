<?php

namespace App\Services;

use App\Support\ItemMatchKey;
use App\Support\ItemName;
use Illuminate\Support\Facades\DB;

/**
 * Work out the single item each post is about, once, and keep it in messages_cleaned_items for the AI
 * item pictures, the matched-posts email and search. docs/developers/reference/message-items.md.
 *
 * Covers live posts (messages_spatial) and posts waiting for a moderator from the last few days,
 * newest first. A post with no row yet is a candidate, so the live posts from before this existed
 * are worked through the same way, a run at a time.
 */
class MessageItemsService
{
    /** Posts per run. 10 requests of 100: comfortably inside a one-minute schedule. */
    public const PER_RUN = 1000;

    private const PENDING_DAYS = 3;

    public function __construct(private ItemCleanerService $cleaner) {}

    /** @return int posts given an item this run */
    public function process(int $limit = self::PER_RUN): int
    {
        if (! $this->cleaner->isConfigured()) {
            return 0;
        }

        $subjects = $this->candidates($limit);
        if (! $subjects) {
            return 0;
        }

        $names = [];
        foreach ($subjects as $msgid => $subject) {
            $names[$msgid] = ItemName::fromSubject($subject);
        }
        $items = $this->cleaner->clean(array_values(array_filter($names)));

        $rows = [];
        foreach ($names as $msgid => $name) {
            $item = $name === '' ? ItemCleanerService::NONE : ($items[$name] ?? null);
            if ($item === null) {
                // Not answered this run: still a candidate next run.
                continue;
            }
            $key = $item === ItemCleanerService::NONE ? '' : ItemMatchKey::key($item);
            $rows[] = [
                'msgid' => $msgid,
                'item' => mb_substr($item, 0, 100),
                'itemkey' => $key === '' ? null : mb_substr($key, 0, 100),
            ];
        }
        if ($rows) {
            DB::table('messages_cleaned_items')->insertOrIgnore($rows);
        }

        return count($rows);
    }

    /** @return array<int,string> msgid => subject, newest first */
    private function candidates(int $limit): array
    {
        $live = DB::table('messages_spatial')
            ->join('messages', 'messages.id', '=', 'messages_spatial.msgid')
            ->leftJoin('messages_cleaned_items', 'messages_cleaned_items.msgid', '=', 'messages_spatial.msgid')
            ->whereNull('messages_cleaned_items.id')
            ->whereIn('messages.type', ['Offer', 'Wanted'])
            ->whereNotNull('messages.subject')
            ->orderByDesc('messages_spatial.arrival')
            ->limit($limit)
            ->pluck('messages.subject', 'messages.id');

        $pending = DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->leftJoin('messages_cleaned_items', 'messages_cleaned_items.msgid', '=', 'messages_groups.msgid')
            ->whereNull('messages_cleaned_items.id')
            ->where('messages_groups.collection', 'Pending')
            ->where('messages_groups.deleted', 0)
            ->where('messages_groups.arrival', '>=', now()->subDays(self::PENDING_DAYS))
            ->whereNull('messages.deleted')
            ->whereIn('messages.type', ['Offer', 'Wanted'])
            ->whereNotNull('messages.subject')
            ->orderByDesc('messages_groups.arrival')
            ->limit($limit)
            ->pluck('messages.subject', 'messages.id');

        // Pending first: they are the newest, and the illustrations job wants them.
        $out = [];
        foreach ([$pending, $live] as $set) {
            foreach ($set as $msgid => $subject) {
                if (count($out) >= $limit) {
                    break 2;
                }
                $out[(int) $msgid] ??= (string) $subject;
            }
        }

        return $out;
    }
}
