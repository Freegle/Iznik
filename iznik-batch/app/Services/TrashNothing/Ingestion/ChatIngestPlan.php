<?php

namespace App\Services\TrashNothing\Ingestion;

use App\Models\ChatMessage;
use App\Services\Mail\Incoming\RoutingResult;

/**
 * What ChatMessageIngestionService::plan() decided for one TN chat-messages record: the chat
 * message to write, or why none is written. Read-only and deterministic, so tn:chat-parity-check
 * can compare it with what the email path wrote without writing anything itself.
 */
final class ChatIngestPlan
{
    /** A message is to be written. */
    public const RESULT_WRITE = 'write';

    /** It was written: apply() has run. */
    public const RESULT_WRITTEN = 'written';

    /** The email path would drop it too, for the reason given. */
    public const RESULT_DROPPED = 'dropped';

    /** Only the API path skips it, for a rule the email path does not have (plan section 3). */
    public const RESULT_SKIPPED = 'skipped';

    /** The record has been written before: tn:sync reads overlapping windows. */
    public const RESULT_DUPLICATE = 'duplicate';

    public function __construct(
        public readonly TnChatMessage $record,
        public readonly string $result,
        public readonly RoutingResult $outcome,
        // The routing context for the routed Loki entry, with the email path's keys.
        public readonly array $context,
        // The chat to write into. Null with a write means apply() creates it.
        public readonly ?int $chatId = null,
        public readonly ?string $chatType = null,
        public readonly ?int $senderId = null,
        // For a User2User chat: the member written to.
        public readonly ?int $recipientId = null,
        // For a User2Mod chat: the community.
        public readonly ?int $groupId = null,
        public readonly string $messageType = ChatMessage::TYPE_DEFAULT,
        public readonly ?int $refmsgid = null,
        public readonly string $text = '',
        public readonly bool $reviewRequired = false,
        // Already mapped to the chat_messages.reportreason enum.
        public readonly ?string $reportReason = null,
        // The chat id the email path marks its notification replied for: the address's own id.
        public readonly ?int $trackChatId = null,
        // Whether the sender's users.lastaccess is set, as the handler the record mirrors does.
        public readonly bool $touchLastAccess = false,
        public readonly ?int $chatMessageId = null,
    ) {}

    public static function notWritten(TnChatMessage $record, string $result, string $reason, array $extra = []): self
    {
        // As the email path's dropped(): the reason replaces the context rather than joining it.
        return new self($record, $result, RoutingResult::DROPPED, array_merge(['routing_reason' => $reason], $extra));
    }

    public function writes(): bool
    {
        return $this->result === self::RESULT_WRITE || $this->result === self::RESULT_WRITTEN;
    }

    public function reason(): ?string
    {
        return $this->context['routing_reason'] ?? null;
    }

    public function withWritten(int $chatId, int $chatMessageId, array $context): self
    {
        return new self(
            $this->record, self::RESULT_WRITTEN, $this->outcome, $context, $chatId, $this->chatType,
            $this->senderId, $this->recipientId, $this->groupId, $this->messageType, $this->refmsgid,
            $this->text, $this->reviewRequired, $this->reportReason, $this->trackChatId,
            $this->touchLastAccess, $chatMessageId,
        );
    }

    public function withResult(string $result, string $reason): self
    {
        return self::notWritten($this->record, $result, $reason);
    }

    /**
     * What the parity check compares with the email path's row, layer 4. The text goes as a hash:
     * the check prints ids and hashes, never what members wrote.
     *
     * @return array<string, mixed>
     */
    public function comparable(): array
    {
        return [
            'chat_id' => $this->chatId,
            'sender_id' => $this->senderId,
            'chat_type' => $this->chatType,
            'type' => $this->messageType,
            'refmsgid' => $this->refmsgid,
            'text_hash' => self::textHash($this->text),
            'review_required' => $this->reviewRequired,
            'report_reason' => $this->reportReason,
        ];
    }

    /** Trimmed, with line endings made alike, before hashing: section 5.3 layer 4. */
    public static function normaliseText(string $text): string
    {
        return trim(preg_replace("/[ \t]+\n/", "\n", str_replace(["\r\n", "\r"], "\n", $text)));
    }

    public static function textHash(string $text): string
    {
        return substr(hash('sha256', self::normaliseText($text)), 0, 16);
    }
}
