<?php

namespace App\Services\TrashNothing\Verify;

use App\Models\ChatMessageTn;
use App\Models\ChatRoom;
use App\Services\Mail\Incoming\RoutingResult;
use App\Services\TrashNothing\Ingestion\ChatIngestPlan;
use App\Services\TrashNothing\Ingestion\ChatMessageIngestionService;
use App\Services\TrashNothing\Ingestion\TnChatMessage;

/**
 * Compares what the email path made of TN's chat mail with what the API path would make of the
 * feed records for the same messages, joined on TN's log id. The layers of
 * plans/2026-11-12-tn-api-chat-ingestion.md, 5.3:
 *
 *   1. coverage: every email has a record (fails);
 *   2. extras: records with no email, by kind (reported);
 *   3. outcome: both made a message, or neither did (fails), apart from what 3.1 explains;
 *   4. content: where both made one, the same chat, sender, type, post, text and hold (fails).
 *
 * Layer 5, the Loki entries, is not compared here yet.
 *
 * Reports ids, kinds, reasons and field names, never text.
 */
class ChatParityChecker
{
    /** Skips only the API path makes, by rules it follows on purpose (plan 3.1). */
    private const EXPLAINED_SKIPS = [
        ChatMessageIngestionService::REASON_SENDER_UNKNOWN,
        ChatMessageIngestionService::REASON_SENDER_NOT_IN_CHAT,
    ];

    /**
     * Hold reasons chats:process-incoming writes (ChatProcessService). Processing can add a hold to
     * a message the email path wrote unheld, never clear one, so an email-side hold with one of
     * these and no API-side hold may be processing's own.
     */
    private const PROCESSING_REASONS = [
        'Spam', 'Last', 'WorryWord', 'Money', 'Link', 'URL on DBL', 'Email', 'Language',
        'Referenced known spammer', 'Greetings spam',
    ];

    public function __construct(private readonly ChatMessageIngestionService $ingestion) {}

    /**
     * @param  array<string, array>  $emails  From ChatEmailInventory, keyed by log id.
     * @param  list<TnChatMessage>  $records  The feed over a window a little wider than the emails'.
     * @param  array{0: string, 1: string}  $window  The emails' window, for which records count as extras.
     */
    public function check(array $emails, array $records, array $window): ChatParityReport
    {
        $report = new ChatParityReport;
        $byLogId = [];
        foreach ($records as $record) {
            $byLogId[$record->logId()] = $record;
        }

        foreach ($emails as $logId => $email) {
            $report->count('email', $email['kind']);
            $record = $byLogId[$logId] ?? null;

            if ($record === null) {
                $report->fail('coverage', ['log_id' => $logId, 'kind' => $email['kind'], 'email_outcome' => $email['outcome']]);
                continue;
            }

            unset($byLogId[$logId]);
            $this->compare($report, $email, $record);
        }

        [$from, $to] = [strtotime($window[0]), strtotime($window[1])];
        foreach ($byLogId as $logId => $record) {
            $date = $record->date !== null ? strtotime($record->date) : null;
            if ($date !== null && ($date < $from || $date > $to)) {
                continue;
            }

            $report->count('extra', $record->kind);
            $extra = ['tn_message_id' => $record->id, 'kind' => $record->kind];
            // Anything between two TN members should never be in the feed.
            if ($this->isBetweenTnMembers($record)) {
                $report->fail('extras', $extra + ['why' => 'between-tn-members']);
            } else {
                $report->note('extras', $extra);
            }
        }

        return $report;
    }

    private function compare(ChatParityReport $report, array $email, TnChatMessage $record): void
    {
        $row = $email['chat_message'];
        $emailWrote = $row !== null
            || in_array($email['outcome'], [RoutingResult::TO_USER->value, RoutingResult::TO_VOLUNTEERS->value], true);

        // As things stood when the email path wrote its copy: before it, in the same chat.
        $plan = $this->ingestion->plan($record, $row['id'] ?? null);
        $apiRow = $this->writtenRow($plan);
        $apiWrites = $plan->writes() || $apiRow !== null;
        $ids = ['log_id' => $record->logId(), 'kind' => $record->kind, 'chat_message_id' => $row['id'] ?? null];

        if ($emailWrote !== $apiWrites) {
            if ($emailWrote && in_array($plan->reason(), self::EXPLAINED_SKIPS, true)) {
                $report->note('explained', $ids + ['reason' => $plan->reason()]);
            } else {
                $report->fail('outcome', $ids + [
                    'email' => $emailWrote ? 'wrote' : ($email['outcome'] ?? 'unknown'),
                    'api' => $apiWrites ? 'writes' : $plan->reason(),
                ]);
            }

            return;
        }

        if (!$emailWrote) {
            $report->count('agree', $record->kind);

            return;
        }

        if ($row === null) {
            // The email path wrote a message whose copy has gone (purged after two days).
            $report->note('unverifiable', $ids + ['why' => 'email-row-gone']);

            return;
        }

        $differences = $this->differences($row, $apiRow ?? $plan->comparable(), $plan);
        $textDiff = array_search('text', $differences, true);
        if ($textDiff !== false && $this->emailHasExtraText($row['message'], $apiRow['text'] ?? $plan->text)) {
            unset($differences[$textDiff]);
            $report->note('email_extra_text', $ids);
        }

        if ($differences === []) {
            $report->count('agree', $record->kind);
        } else {
            $report->fail('content', $ids + ['fields' => array_values($differences)]);
        }
    }

    /**
     * The fields that differ between the email path's row and the API path's.
     *
     * @return list<string>
     */
    private function differences(array $row, array $api, ChatIngestPlan $plan): array
    {
        $diff = [];

        if ($api['chat_id'] !== null) {
            if ($api['chat_id'] !== $row['chat_id']) {
                $diff[] = 'chat_id';
            }
        } elseif (!$this->chatWouldMatch($row, $plan)) {
            // The API path would make the chat. It must be between the same people.
            $diff[] = 'chat_id';
        }

        foreach (['sender_id', 'chat_type', 'type', 'refmsgid'] as $field) {
            if ($api[$field] !== $row[$field]) {
                $diff[] = $field;
            }
        }

        if ($api['text_hash'] !== ChatIngestPlan::textHash($row['message'])) {
            $diff[] = 'text';
        }

        if ($this->holdDiffers($row, $api)) {
            $diff[] = 'hold';
        }

        return $diff;
    }

    /**
     * Holds set on arrival are kept by processing, which can add one but not clear one. So an
     * API-side hold the email row lacks is a difference, two holds must agree on the reason, and an
     * email-side hold alone is processing's when its reason is one processing writes.
     */
    private function holdDiffers(array $row, array $api): bool
    {
        if ($api['review_required'] && !$row['review_required']) {
            return true;
        }
        if ($api['review_required'] && $row['review_required']) {
            return $api['report_reason'] !== $row['report_reason'];
        }
        if ($row['review_required']) {
            return !in_array($row['report_reason'], self::PROCESSING_REASONS, true);
        }

        return false;
    }

    private function chatWouldMatch(array $row, ChatIngestPlan $plan): bool
    {
        if ($plan->chatType === ChatRoom::TYPE_USER2MOD) {
            return $row['chat_type'] === ChatRoom::TYPE_USER2MOD && $row['user1'] === $plan->senderId && $row['group_id'] === $plan->groupId;
        }

        $users = [$row['user1'], $row['user2']];
        sort($users);
        $planned = [$plan->senderId, $plan->recipientId];
        sort($planned);

        return $users === $planned;
    }

    /** The row the API path already wrote for the record, after --apply or after the cutover. */
    private function writtenRow(ChatIngestPlan $plan): ?array
    {
        if ($plan->result !== ChatIngestPlan::RESULT_DUPLICATE && $plan->chatMessageId === null) {
            return null;
        }

        $chatMsgId = $plan->chatMessageId ?? ChatMessageTn::where('tnmessageid', $plan->record->id)->value('chatmsgid');
        $row = $chatMsgId !== null ? app(ChatEmailInventory::class)->chatMessageRow((int) $chatMsgId) : null;
        if ($row === null) {
            return null;
        }

        return [
            'chat_id' => $row['chat_id'],
            'sender_id' => $row['sender_id'],
            'chat_type' => $row['chat_type'],
            'type' => $row['type'],
            'refmsgid' => $row['refmsgid'],
            'text_hash' => ChatIngestPlan::textHash($row['message']),
            'text' => $row['message'],
            'review_required' => $row['review_required'],
            'report_reason' => $row['report_reason'],
        ];
    }

    /** The email had more text than the API: reported apart, because it is not lost text. */
    private function emailHasExtraText(string $emailText, string $apiText): bool
    {
        $api = ChatIngestPlan::normaliseText($apiText);

        return $api !== '' && str_contains(ChatIngestPlan::normaliseText($emailText), $api);
    }

    private function isBetweenTnMembers(TnChatMessage $record): bool
    {
        if (!in_array($record->kind, [TnChatMessage::KIND_POST_REPLY, TnChatMessage::KIND_USER_MESSAGE], true)
            || $record->fdRecipientUserId === null) {
            return false;
        }

        $recipient = \App\Models\User::find($record->fdRecipientUserId);

        return $recipient !== null && $recipient->isTrashNothingAccount();
    }
}
