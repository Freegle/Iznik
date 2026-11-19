<?php

namespace App\Services\TrashNothing\Sync;

use App\Services\LokiService;
use App\Services\TrashNothing\Ingestion\ChatIngestPlan;
use App\Services\TrashNothing\Ingestion\ChatMessageIngestionService;
use App\Services\TrashNothing\Ingestion\TnChatMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads TrashNothing's chat-messages feed (GET /fd/api/chat-messages, partner key) and hands each
 * record to ChatMessageIngestionService. See plans/2026-11-12-tn-api-chat-ingestion.md, 4.1.
 *
 * Until the cutover it only plans: each record is traced with what would be written, and nothing
 * is. The email path is still the one writing these messages.
 *
 * A response is never logged whole, and no record's text is ever logged: these are private
 * conversations.
 */
class ChatMessageSyncer
{
    use ReadsTnFeedByDay;

    private const PAGE_SIZE = 100;

    private bool $fetchFailed = false;

    private ChatMessageIngestionService $ingestion;

    /** @var array<string, int> How many records ended each way in the last sync(), by result. */
    private array $results = [];

    public function __construct(
        private readonly bool $dryRun,
        private readonly bool $localTesting,
        private readonly string $apiKey,
        private readonly string $apiBaseUrl,
        private readonly LokiService $loki,
        // Shared with the other TN syncers: TN rate-limits per key, across every feed.
        private readonly ?TrashNothingRateLimiter $rateLimiter = null,
        ?ChatMessageIngestionService $ingestion = null,
    ) {
        $this->ingestion = $ingestion ?? new ChatMessageIngestionService($dryRun);
    }

    /**
     * Whether the last sync() stopped at a page it could not fetch. The records after it were
     * never seen, so the caller must not move the stored date past them.
     */
    public function fetchFailed(): bool
    {
        return $this->fetchFailed;
    }

    /** @return array<string, int> */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * @return array{int, string|null} [count, maxDate]
     */
    public function sync(string $from, string $to): array
    {
        $this->results = [];
        $count = 0;
        $maxDate = null;

        foreach ($this->records($from, $to) as $record) {
            $count++;

            if ($record->date !== null && (!$maxDate || strtotime($record->date) > strtotime($maxDate))) {
                $maxDate = $record->date;
            }

            try {
                $plan = $this->ingestion->plan($record);
                $this->results[$plan->result] = ($this->results[$plan->result] ?? 0) + 1;
                $this->trace($plan);
            } catch (\Throwable $e) {
                $this->results['error'] = ($this->results['error'] ?? 0) + 1;
                Log::error('TN sync: chat message failed', ['tn_message_id' => $record->id, 'error' => $e->getMessage()]);
                if (function_exists('\Sentry\captureException')) {
                    \Sentry\captureException($e);
                }
            }
        }

        return [$count, $maxDate];
    }

    /**
     * Every record in the window, in (date, id) order. TN documents no order, and the review chain
     * and the chat display go by chat_messages.id, so a window is written oldest first (3.6).
     *
     * @return list<TnChatMessage>
     */
    public function records(string $from, string $to): array
    {
        $records = [];

        foreach ($this->pages($from, $to) as $page => $rows) {
            Log::info('TN-SYNC-TRACE [CHAT-PAGE] page=' . $page . ' count=' . count($rows));

            foreach ($rows as $row) {
                try {
                    $records[] = TnChatMessage::fromArray(is_array($row) ? $row : []);
                } catch (\InvalidArgumentException $e) {
                    $this->results['invalid'] = ($this->results['invalid'] ?? 0) + 1;
                    Log::warning('TN sync: chat message record without an id skipped');
                }
            }
        }

        usort($records, fn (TnChatMessage $a, TnChatMessage $b) => strcmp($a->sortKey(), $b->sortKey()));

        return $records;
    }

    private function trace(ChatIngestPlan $plan): void
    {
        $r = $plan->record;
        $line = 'TN-SYNC-TRACE [CHAT-PLAN] tn_message_id=' . $r->id . ' kind=' . $r->kind . ' result=' . $plan->result
            . ' outcome=' . $plan->outcome->value;

        if ($plan->writes()) {
            $line .= ' chat_id=' . ($plan->chatId ?? 'new') . ' chat_type=' . $plan->chatType
                . ' user_id=' . $plan->senderId . ' type=' . $plan->messageType
                . ' refmsgid=' . ($plan->refmsgid ?? 'null')
                . ' review=' . ($plan->reviewRequired ? ($plan->reportReason ?? '1') : '0')
                . ' text_hash=' . ChatIngestPlan::textHash($plan->text);
        } else {
            $line .= ' reason=' . $plan->reason();
        }

        Log::info($line);
    }

    /**
     * @return array|null  Records, or null when the page could not be read.
     */
    private function fetchPage(int $page, string $from, string $to): ?array
    {
        if ($this->localTesting) {
            $file = base_path("tests/fixtures/tn_sync/chat_messages_page_{$page}.json");
            if (!file_exists($file)) {
                return [];
            }
            $payload = json_decode(file_get_contents($file), true);

            return is_array($payload) ? ($payload['chat_messages'] ?? []) : [];
        }

        ($this->rateLimiter ?? app(TrashNothingRateLimiter::class))->await();

        $response = Http::get("{$this->apiBaseUrl}/chat-messages", [
            'key' => $this->apiKey,
            'page' => $page,
            'per_page' => self::PAGE_SIZE,
            'date_min' => $from,
            'date_max' => $to,
        ]);

        if (!$response->successful()) {
            // Status only: the body of a chat feed could quote a member, and the URL holds the key.
            Log::error('TN sync: chat-messages API failed on page ' . $page, [
                'status' => $response->status(),
                'date_min' => $from,
                'date_max' => $to,
            ]);

            return null;
        }

        $rows = $response->json('chat_messages');

        return is_array($rows) ? $rows : null;
    }
}
