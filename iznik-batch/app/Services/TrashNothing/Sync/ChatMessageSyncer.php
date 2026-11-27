<?php

namespace App\Services\TrashNothing\Sync;

use App\Services\LokiService;
use App\Services\TrashNothing\Ingestion\ChatIngestPlan;
use App\Services\TrashNothing\Ingestion\ChatMessageIngestionService;
use App\Services\TrashNothing\Ingestion\DeliberateSkips;
use App\Services\TrashNothing\Ingestion\TnChatMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads TrashNothing's chat-messages feed (GET /fd/api/chat-messages, partner key) and hands each
 * record to ChatMessageIngestionService. See plans/2026-11-12-tn-api-chat-ingestion.md, 4.1.
 *
 * Until the cutover it only plans: each record is traced with what would be written, and nothing
 * is. The email path is still the one writing these messages. Once it writes, each record gets one
 * routed Loki entry, as each email does on the email path, and only the kinds the cutover has moved
 * (cutOverKinds()) are written: the rest still arrive by email.
 *
 * A response is never logged whole, and no record's text is ever logged: these are private
 * conversations.
 */
class ChatMessageSyncer
{
    use ReadsTnFeedByDay;

    private const PAGE_SIZE = 100;

    /** A kind the cutover has not moved yet: it still arrives, and is written, by email. */
    public const REASON_NOT_CUT_OVER = 'tn-kind-not-cut-over';

    /** DeliberateSkips keys for chat records, apart from the posts that share the store. */
    public const SKIP_KEY_PREFIX = 'chat:';

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
        // Write what the feed carries: the cutover (freegle.trashnothing.ingest_chat_via_api).
        private readonly bool $write = false,
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
                if ($this->write) {
                    $plan = $this->writePlan($plan);
                }
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

    /**
     * Plan one record and, when this syncer writes, write it as sync() would: the coverage check's
     * backfill, so a late message gets the same checks, note and routed entry as any other.
     */
    public function ingestOne(TnChatMessage $record): ChatIngestPlan
    {
        $plan = $this->ingestion->plan($record);

        return $this->write ? $this->writePlan($plan) : $plan;
    }

    /**
     * The kinds of TN chat message the cutover has moved, from freegle.trashnothing.ingest_chat_kinds.
     * The gate reads the same list, so a kind is never both skipped by email and left unwritten.
     *
     * @return list<string>
     */
    public static function cutOverKinds(): array
    {
        $kinds = array_map('trim', explode(',', (string) config('freegle.trashnothing.ingest_chat_kinds', '')));

        return array_values(array_intersect(TnChatMessage::KINDS, $kinds));
    }

    private function writePlan(ChatIngestPlan $plan): ChatIngestPlan
    {
        $record = $plan->record;

        if ($plan->writes() && !in_array($record->kind, self::cutOverKinds(), true)) {
            // The email path still routes this kind, so writing it here too would write it twice.
            $plan = $plan->withResult(ChatIngestPlan::RESULT_SKIPPED, self::REASON_NOT_CUT_OVER);
        } else {
            $plan = $this->ingestion->apply($plan);
        }

        if ($plan->result === ChatIngestPlan::RESULT_DUPLICATE) {
            return $plan;
        }

        // A record not written is noted with why, for tn:verify-chat-coverage, and the note keeps a
        // window read again from logging it twice: a written one is kept from that by its row.
        if (!$plan->writes()) {
            $key = self::SKIP_KEY_PREFIX . $record->id;
            if (DeliberateSkips::reasonFor($key) !== null) {
                return $plan;
            }
            DeliberateSkips::record($key, (string) $plan->reason());

            if ($plan->reason() === ChatMessageIngestionService::REASON_SENDER_NOT_IN_CHAT) {
                $this->reportOncePerDay($plan);
            }
        }

        $this->logRouted($plan);

        return $plan;
    }

    /** One routed entry per record, as the email path writes one per email. */
    private function logRouted(ChatIngestPlan $plan): void
    {
        $record = $plan->record;
        $context = $plan->context + [
            'tn_message_id' => $record->id,
            'tn_user_id' => $record->tnUserId ?: null,
            'kind' => $record->kind,
        ];
        if ($plan->chatMessageId !== null) {
            $context['chat_message_id'] = $plan->chatMessageId;
        }
        if ($this->dryRun) {
            $context['dry_run'] = true;
        }

        $this->loki->logIngestedChatMessage(
            envelopeTo: $this->envelopeTo($plan),
            subject: (string) ($record->subject ?? ''),
            messageId: $record->id,
            routingOutcome: $plan->outcome->value,
            context: $context,
        );
    }

    /** The address the email for this message goes to, where the record says what it is. */
    private function envelopeTo(ChatIngestPlan $plan): string
    {
        $r = $plan->record;

        if ($r->kind === TnChatMessage::KIND_CHAT_REPLY && $r->fdChatId !== null && $plan->senderId !== null) {
            return "notify-{$r->fdChatId}-{$plan->senderId}@" . config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        }

        $group = $plan->context['group_name'] ?? null;
        if ($group) {
            return $group . '-volunteers@' . config('freegle.mail.group_domain', 'groups.ilovefreegle.org');
        }

        return '';
    }

    /**
     * Sentry hears once a day that a sender TN names is not in the chat TN names. The email path
     * files such a message on an account that does not hold the sender's TN id, so it is most likely
     * an account two members share, which a person can split with tn:split-shared-account.
     */
    private function reportOncePerDay(ChatIngestPlan $plan): void
    {
        $reason = (string) $plan->reason();
        if (!Cache::add('tn-chat:reported:' . $reason . ':' . now()->format('Y-m-d'), true, now()->addDay())) {
            return;
        }

        $message = "TN chat message skipped ({$reason}): user " . ($plan->context['user_id'] ?? '?')
            . ' is not in chat ' . ($plan->context['chat_id'] ?? '?') . ', look for a shared account to split';
        Log::warning($message, ['tn_message_id' => $plan->record->id]);
        if (function_exists('\Sentry\captureMessage')) {
            \Sentry\captureMessage($message);
        }
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
     * One record by TN's id (GET /fd/api/chat-messages/{id}), for the coverage check to ask TN
     * about a message that arrived by email and was never written.
     *
     * @return array{0: string, 1: TnChatMessage|null}  'found' with the record, 'absent' when TN has
     *                                                 none, or 'error' when TN could not be asked.
     */
    public function fetchRecord(string $id): array
    {
        ($this->rateLimiter ?? app(TrashNothingRateLimiter::class))->await();

        $response = Http::get("{$this->apiBaseUrl}/chat-messages/" . rawurlencode($id), ['key' => $this->apiKey]);

        if ($response->status() === 404) {
            return ['absent', null];
        }

        $row = $response->successful() ? ($response->json('chat_message') ?? $response->json()) : null;
        if (!is_array($row)) {
            Log::warning('TN sync: chat-messages lookup failed', ['tn_message_id' => $id, 'status' => $response->status()]);

            return ['error', null];
        }

        try {
            return ['found', TnChatMessage::fromArray($row)];
        } catch (\InvalidArgumentException) {
            return ['error', null];
        }
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
