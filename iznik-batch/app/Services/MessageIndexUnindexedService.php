<?php

namespace App\Services;

use App\Models\MessageGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageIndexUnindexedService
{
    // Common words excluded from the search index (matches V1 Search::$common).
    private array $common = [
        'the', 'old', 'new', 'please', 'thanks', 'with', 'offer', 'taken', 'wanted', 'received',
        'attachment', 'offered', 'and', 'freegle', 'freecycle', 'for', 'large', 'small', 'are',
        'but', 'not', 'you', 'all', 'any', 'can', 'her', 'was', 'one', 'our', 'out', 'day', 'get',
        'has', 'him', 'how', 'now', 'see', 'two', 'who', 'did', 'its', 'let', 'she', 'too', 'use',
        'plz', 'of', 'to', 'in', 'it', 'is', 'be', 'as', 'at', 'so', 'we', 'he', 'by', 'or', 'on',
        'do', 'if', 'me', 'my', 'up', 'an', 'go', 'no', 'us', 'am', 'working', 'broken', 'black',
        'white', 'grey', 'blue', 'green', 'red', 'yellow', 'brown', 'orange', 'pink', 'machine',
        'size', 'set', 'various', 'assorted', 'different', 'bits', 'ladies', 'gents', 'kids', 'nice',
        'brand', 'pack', 'soft', 'single', 'double', 'top', 'plastic', 'electric', 'unopened',
    ];

    public function indexUnindexedMessages(): array
    {
        $cutoff = now()->subDays(31)->startOfDay()->format('Y-m-d');

        $msgs = DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('messages_groups.deleted', 0)
            ->where('messages_groups.arrival', '>=', $cutoff)
            ->whereNotIn('messages_groups.msgid', function ($q) {
                $q->select('msgid')->from('messages_index');
            })
            ->orderByDesc('messages_groups.arrival')
            ->select('messages_groups.msgid', 'messages.subject', 'messages_groups.arrival', 'messages_groups.groupid')
            ->get();

        Log::info("MessageIndexUnindexed: indexing {$msgs->count()} messages");

        $count = 0;
        $total = $msgs->count();

        foreach ($msgs as $msg) {
            $toadd = $msg->subject;

            [$type, $item] = $this->parseSubject($msg->subject);
            if ($item) {
                $toadd = $item;
            }

            $arrivalTimestamp = Carbon::parse($msg->arrival)->timestamp;

            $this->indexString($msg->msgid, $toadd, $arrivalTimestamp, $msg->groupid);

            $count++;
            Log::info("{$count} / {$total}");
        }

        DB::table('words_cache')->delete();

        return ['indexed' => $count];
    }

    private function parseSubject(string $subject): array
    {
        $type = null;
        $item = null;
        $location = null;

        $p = strpos($subject, ':');

        if ($p !== false) {
            $startp = $p;
            $rest = trim(substr($subject, $p + 1));
            $p = strlen($rest) - 1;

            if (substr($rest, -1) === ')') {
                $count = 0;

                do {
                    $curr = substr($rest, $p, 1);

                    if ($curr === '(') {
                        $count--;
                    } elseif ($curr === ')') {
                        $count++;
                    }

                    $p--;
                } while ($count > 0 && $p > 0);

                if ($count === 0) {
                    $type = trim(substr($subject, 0, $startp));
                    $location = trim(substr($rest, $p + 2, strlen($rest) - $p - 3));
                    $item = trim(substr($rest, 0, $p));
                }
            }
        }

        return [$type, $item, $location];
    }

    private function getWords(string $string): array
    {
        $string = preg_replace('/[^a-z0-9]+/i', ' ', strtolower($string));
        $words = preg_split('/\s+/', $string);
        $words = array_diff($words, $this->common);

        $ret = [];
        foreach ($words as $word) {
            if (strlen($word) >= 2) {
                $ret[] = substr($word, 0, 10); // words column is varchar(10)
            }
        }

        return $ret;
    }

    private function getOrCreateWordId(string $word): ?int
    {
        $existing = DB::table('words')->where('word', $word)->value('id');

        if ($existing) {
            return (int) $existing;
        }

        DB::statement(
            "INSERT IGNORE INTO words (word, firstthree, soundex) VALUES (?, ?, SUBSTRING(SOUNDEX(?), 1, 10))",
            [$word, substr($word, 0, 3), $word]
        );

        return (int) DB::table('words')->where('word', $word)->value('id');
    }

    private function indexString(int $msgid, string $string, int $arrivalTimestamp, ?int $groupid): void
    {
        foreach ($this->getWords($string) as $word) {
            $wordId = $this->getOrCreateWordId($word);

            if (!$wordId) {
                continue;
            }

            DB::table('messages_index')->upsert(
                ['msgid' => $msgid, 'wordid' => $wordId, 'arrival' => -$arrivalTimestamp, 'groupid' => $groupid],
                ['msgid', 'wordid'],
                ['arrival']
            );

            DB::table('words')
                ->where('id', $wordId)
                ->update(['popularity' => DB::raw('-(SELECT COUNT(*) FROM messages_index WHERE wordid = ' . $wordId . ')')]);
        }
    }
}
