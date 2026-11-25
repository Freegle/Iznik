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

        $api = $apiRow ?? $plan->comparable();
        $apiText = $apiRow['text'] ?? $plan->text;
        $differences = $this->differences($row, $api, $plan);

        $textDiff = array_search('text', $differences, true);
        if ($textDiff !== false && $this->emailHasExtraText($row['message'], $apiText)) {
            unset($differences[$textDiff]);
            $report->note('email_extra_text', $ids);
        }

        // The email path found the sender by address and the API path by TN id, and the TN id is
        // on the account the API path chose (plan 3.1). The chat follows the sender, so it differs
        // too. Reported apart: each is a message the email path filed on the wrong account.
        if (in_array('sender_id', $differences, true) && $this->senderIsTheTnIdHolder($row, $api, $record)) {
            $differences = array_values(array_diff($differences, ['sender_id', 'chat_id']));
            $report->note('explained', $ids + [
                'reason' => 'tn-id-on-another-account',
                'email_sender' => $row['sender_id'],
                'api_sender' => $api['sender_id'],
            ]);
        }

        if ($differences === []) {
            $report->count('agree', $record->kind);
        } else {
            $report->fail('content', $ids + ['fields' => array_values($differences)]
                + $this->detail($differences, $row, $api, $apiText));
        }
    }

    /** The API path's sender holds the record's TN id and the email path's does not. */
    private function senderIsTheTnIdHolder(array $row, array $api, TnChatMessage $record): bool
    {
        if ($record->tnUserId === 0 || $api['sender_id'] === null) {
            return false;
        }

        $tnIds = \App\Models\User::whereIn('id', [$row['sender_id'], $api['sender_id']])->pluck('tnuserid', 'id');

        return (int) ($tnIds[$api['sender_id']] ?? 0) === $record->tnUserId
            && (int) ($tnIds[$row['sender_id']] ?? 0) !== $record->tnUserId;
    }

    /**
     * What a person needs to see why a field differs, without the text: the two holds and the hold
     * on the message before (as it is now, which a moderator may have changed since), the senders,
     * and for text, the lengths and where the two first part.
     */
    private function detail(array $differences, array $row, array $api, string $apiText): array
    {
        $detail = [];

        if (in_array('hold', $differences, true)) {
            $detail['email_hold'] = $row['review_required'] ? ($row['report_reason'] ?? 'held') : null;
            $detail['api_hold'] = $api['review_required'] ? ($api['report_reason'] ?? 'held') : null;
            $prev = \Illuminate\Support\Facades\DB::table('chat_messages')
                ->where('chatid', $row['chat_id'])
                ->where('id', '<', $row['id'])
                ->orderByDesc('id')
                ->first(['id', 'reviewrequired', 'reportreason']);
            $detail['previous'] = $prev === null ? null : [
                'id' => (int) $prev->id,
                'hold_now' => $prev->reviewrequired ? ($prev->reportreason ?? 'held') : null,
            ];
        }

        if (array_intersect(['sender_id', 'chat_id'], $differences) !== []) {
            $detail['email_sender'] = $row['sender_id'];
            $detail['api_sender'] = $api['sender_id'];
            $detail['email_chat'] = $row['chat_id'];
            $detail['api_chat'] = $api['chat_id'];
        }

        if (in_array('text', $differences, true)) {
            $email = ChatIngestPlan::normaliseText($row['message']);
            $apiNorm = ChatIngestPlan::normaliseText($apiText);
            $detail['email_length'] = mb_strlen($email);
            $detail['api_length'] = mb_strlen($apiNorm);
            $detail['same_for_first'] = $this->commonPrefixLength($email, $apiNorm);
        }

        if (in_array('type', $differences, true) || in_array('refmsgid', $differences, true)) {
            $detail['email_type'] = $row['type'] . '/' . ($row['refmsgid'] ?? 'null');
            $detail['api_type'] = $api['type'] . '/' . ($api['refmsgid'] ?? 'null');
        }

        return $detail;
    }

    private function commonPrefixLength(string $a, string $b): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $i;
            }
        }

        return $n;
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
