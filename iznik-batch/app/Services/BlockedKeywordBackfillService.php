<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\ChatMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies Freegle-wide 'block' concern keywords to content that already got through.
 *
 * The content checks run when a chat message or post arrives, so a keyword added
 * after a wave of scam mail catches nothing that is already in members' chats or
 * live on the site. This service re-applies the block keywords to a recent window:
 *
 * - Chat messages that were delivered (reviewrequired = 0, reviewrejected = 0) and
 *   match are marked reviewrequired = 0, reviewrejected = 1 - the row a moderator's
 *   Reject writes, and what the chat processor now writes for a block hit.
 * - Posts that match are removed the way a moderator's Spam action removes them
 *   (iznik-server-go/message/message.go handleSpam): a messages_spamham row, every
 *   live messages_groups row marked deleted, and once none remain messages.deleted
 *   set and a freebie_alerts_remove task queued.
 *
 * Matching goes through ContentCheckService::checkBlockKeywords, the same test the
 * processor applies, so the two can never disagree about what a keyword means.
 *
 * Every write is a single-row statement with a pause after it. Production rows are
 * changed one at a time; the pause keeps a large run from becoming a write storm.
 * Runs are idempotent: an already-rejected message or already-deleted post is not a
 * candidate, so a re-run over the same window changes nothing.
 */
class BlockedKeywordBackfillService
{
    /** Pause after each row written, in microseconds. */
    public int $pauseMicros = 20000;

    public function __construct(private readonly ContentCheckService $contentCheck)
    {
    }

    /**
     * @param int[]|null $keywordIds Restrict to these concern_keywords ids (null = every global block keyword).
     * @param callable|null $progress Called with a one-line string as work proceeds.
     * @return array{chat: array, posts: array}
     */
    public function run(
        CarbonInterface $since,
        ?array $keywordIds = null,
        bool $dryRun = false,
        ?int $limit = null,
        ?callable $progress = null
    ): array {
        $keywords = $this->contentCheck->globalBlockKeywords($keywordIds);

        if ($keywords->isEmpty()) {
            Log::info('BlockedKeywordBackfill: no global block keywords to apply', ['ids' => $keywordIds]);

            return [
                'chat' => $this->emptyStats(),
                'posts' => $this->emptyStats(),
            ];
        }

        $ids = $keywords->pluck('id')->map(fn($id) => (int) $id)->all();

        $chat = $this->backfillChat($since, $ids, $dryRun, $limit, $progress);
        $posts = $this->backfillPosts($since, $ids, $dryRun, $limit, $progress);

        Log::info('BlockedKeywordBackfill: run complete', [
            'since' => $since->toDateTimeString(),
            'keyword_ids' => $ids,
            'dry_run' => $dryRun,
            'chat' => $this->summarise($chat),
            'posts' => $this->summarise($posts),
        ]);

        return ['chat' => $chat, 'posts' => $posts];
    }

    /**
     * Delivered chat messages in the window that match a block keyword.
     *
     * Only member-entered text is tested (the same types the processor checks);
     * system and templated messages carry nothing a member wrote.
     */
    private function backfillChat(CarbonInterface $since, array $ids, bool $dryRun, ?int $limit, ?callable $progress): array
    {
        $stats = $this->emptyStats();

        $query = DB::table('chat_messages')
            ->where('date', '>=', $since)
            ->where('reviewrequired', 0)
            ->where('reviewrejected', 0)
            ->whereIn('type', [
                ChatMessage::TYPE_DEFAULT,
                ChatMessage::TYPE_INTERESTED,
                ChatMessage::TYPE_REPORTEDUSER,
                ChatMessage::TYPE_ADDRESS,
            ])
            ->select('id', 'userid', 'message')
            ->orderBy('id');

        $query->chunkById(500, function ($rows) use (&$stats, $ids, $dryRun, $limit, $progress) {
            foreach ($rows as $row) {
                $stats['scanned']++;

                $hit = $this->contentCheck->checkBlockKeywords('', (string) ($row->message ?? ''), $ids);
                if ($hit === null) {
                    continue;
                }

                $this->recordMatch($stats, (int) $row->id, $hit);

                if (!$dryRun) {
                    $changed = DB::table('chat_messages')
                        ->where('id', $row->id)
                        ->where('reviewrejected', 0)
                        ->update(['reviewrequired' => 0, 'reviewrejected' => 1]);

                    if ($changed) {
                        $stats['changed']++;
                        Log::info('BlockedKeywordBackfill: chat message rejected', [
                            'chatmsgid' => (int) $row->id,
                            'userid' => (int) $row->userid,
                            'keyword' => $hit['keyword'] ?? null,
                        ]);
                    }

                    $this->pause();
                }

                if ($progress && $stats['matched'] % 500 === 0) {
                    $progress("chat: {$stats['scanned']} scanned, {$stats['matched']} matched, {$stats['changed']} changed");
                }

                if ($limit !== null && $stats['matched'] >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $stats;
    }

    /**
     * Live posts that arrived in the window and match a block keyword in their
     * subject or body.
     */
    private function backfillPosts(CarbonInterface $since, array $ids, bool $dryRun, ?int $limit, ?callable $progress): array
    {
        $stats = $this->emptyStats();

        $query = DB::table('messages')
            ->where('arrival', '>=', $since)
            ->whereNull('deleted')
            ->select('id', 'fromuser', 'subject', 'textbody')
            ->orderBy('id');

        $query->chunkById(200, function ($rows) use (&$stats, $ids, $dryRun, $limit, $progress) {
            foreach ($rows as $row) {
                $stats['scanned']++;

                $hit = $this->contentCheck->checkBlockKeywords(
                    (string) ($row->subject ?? ''),
                    (string) ($row->textbody ?? ''),
                    $ids
                );
                if ($hit === null) {
                    continue;
                }

                $this->recordMatch($stats, (int) $row->id, $hit);

                if (!$dryRun) {
                    if ($this->spamPost((int) $row->id)) {
                        $stats['changed']++;
                        Log::info('BlockedKeywordBackfill: post removed as spam', [
                            'msgid' => (int) $row->id,
                            'userid' => (int) $row->fromuser,
                            'keyword' => $hit['keyword'] ?? null,
                        ]);
                    }
                }

                if ($progress && $stats['matched'] % 100 === 0) {
                    $progress("posts: {$stats['scanned']} scanned, {$stats['matched']} matched, {$stats['changed']} changed");
                }

                if ($limit !== null && $stats['matched'] >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $stats;
    }

    /**
     * Remove one post the way the moderator Spam action does. Returns whether
     * anything changed.
     */
    private function spamPost(int $msgid): bool
    {
        $changed = false;

        // msgid is unique in messages_spamham, so this is one row either way.
        DB::table('messages_spamham')->upsert(
            [['msgid' => $msgid, 'spamham' => 'Spam']],
            ['msgid'],
            ['spamham']
        );
        $this->pause();

        $groups = DB::table('messages_groups')
            ->where('msgid', $msgid)
            ->where('deleted', 0)
            ->pluck('groupid');

        foreach ($groups as $groupid) {
            $n = DB::table('messages_groups')
                ->where('msgid', $msgid)
                ->where('groupid', $groupid)
                ->where('deleted', 0)
                ->update(['deleted' => 1]);
            $changed = $changed || $n > 0;
            $this->pause();
        }

        // Read the count from the writer so a lagging replica cannot report a
        // row we have just marked as still live.
        $remaining = DB::table('messages_groups')
            ->useWritePdo()
            ->where('msgid', $msgid)
            ->where('deleted', 0)
            ->count();

        if ($remaining === 0) {
            $n = DB::table('messages')
                ->where('id', $msgid)
                ->whereNull('deleted')
                ->update(['deleted' => now()]);
            $this->pause();

            if ($n > 0) {
                $changed = true;
                BackgroundTask::create([
                    'task_type' => BackgroundTask::TASK_FREEBIE_ALERTS_REMOVE,
                    'data' => ['msgid' => $msgid],
                    'created_at' => now(),
                    'attempts' => 0,
                ]);
            }
        }

        return $changed;
    }

    private function recordMatch(array &$stats, int $id, array $hit): void
    {
        $stats['matched']++;
        $keyword = (string) ($hit['keyword'] ?? '?');
        $stats['by_keyword'][$keyword] = ($stats['by_keyword'][$keyword] ?? 0) + 1;
        if (count($stats['samples']) < 5) {
            $stats['samples'][] = $id;
        }
    }

    private function emptyStats(): array
    {
        return ['scanned' => 0, 'matched' => 0, 'changed' => 0, 'by_keyword' => [], 'samples' => []];
    }

    private function summarise(array $stats): array
    {
        return [
            'scanned' => $stats['scanned'],
            'matched' => $stats['matched'],
            'changed' => $stats['changed'],
            'by_keyword' => $stats['by_keyword'],
        ];
    }

    private function pause(): void
    {
        if ($this->pauseMicros > 0) {
            usleep($this->pauseMicros);
        }
    }
}
