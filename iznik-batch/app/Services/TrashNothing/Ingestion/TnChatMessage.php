<?php

namespace App\Services\TrashNothing\Ingestion;

/**
 * One record of TrashNothing's chat-messages feed (GET /fd/api/chat-messages): a message a TN
 * member sent to Freegle, which TN also emails to us until the cutover. The fields are the
 * contract asked of TN in plans/2026-11-12-tn-api-chat-ingestion.md, section 7.1.
 *
 * A record is private conversation, so nothing here is ever logged whole.
 */
final class TnChatMessage
{
    /** A reply in an existing chat: the email went to our notify- address (row 1). */
    public const KIND_CHAT_REPLY = 'chat_reply';

    /** A first reply to a Freegle member's post (row 2). */
    public const KIND_POST_REPLY = 'post_reply';

    /** A message to a Freegle member not about a post (row 3). */
    public const KIND_USER_MESSAGE = 'user_message';

    /** A message to a community's volunteers (row 4). */
    public const KIND_VOLUNTEERS = 'volunteers';

    /** A report of a member or a post, to a community's volunteers (row 5). */
    public const KIND_REPORT = 'report';

    /** TN's own notice to a community's volunteers (row 9). Not carried yet: decision 7. */
    public const KIND_TN_NOTICE = 'tn_notice';

    public const KINDS = [
        self::KIND_CHAT_REPLY,
        self::KIND_POST_REPLY,
        self::KIND_USER_MESSAGE,
        self::KIND_VOLUNTEERS,
        self::KIND_REPORT,
        self::KIND_TN_NOTICE,
    ];

    public function __construct(
        // TN's id for the message: the X-Trash-Nothing-Log-Id of the email TN sends for it.
        public readonly string $id,
        // When TN sent it to Freegle. Stored as chat_messages_tn.tndate, never as the message's date.
        public readonly ?string $date,
        public readonly int $tnUserId,
        public readonly ?int $fdUserId,
        public readonly string $kind,
        public readonly ?int $fdChatId,
        public readonly ?int $fdMsgid,
        public readonly ?int $fdRecipientUserId,
        public readonly ?int $fdGroupId,
        public readonly ?int $reportedFdUserId,
        public readonly ?int $reportedFdMsgid,
        public readonly ?string $subject,
        public readonly string $text,
        public readonly ?string $displayName,
        public readonly bool $automated,
        // Only when id is not the log id of the email TN sent for the same message.
        public readonly ?string $emailLogId,
    ) {}

    /**
     * @throws \InvalidArgumentException  for a record with no id, which cannot be made idempotent
     */
    public static function fromArray(array $r): self
    {
        $id = trim((string) ($r['id'] ?? ''));
        if ($id === '') {
            throw new \InvalidArgumentException('TN chat message record has no id');
        }

        return new self(
            id: $id,
            date: self::str($r['date'] ?? null),
            tnUserId: (int) ($r['tn_user_id'] ?? 0),
            fdUserId: self::int($r['fd_user_id'] ?? null),
            kind: (string) ($r['kind'] ?? ''),
            fdChatId: self::int($r['fd_chat_id'] ?? null),
            fdMsgid: self::int($r['fd_msgid'] ?? null),
            fdRecipientUserId: self::int($r['fd_recipient_user_id'] ?? null),
            fdGroupId: self::int($r['fd_group_id'] ?? null),
            reportedFdUserId: self::int($r['reported_fd_user_id'] ?? null),
            reportedFdMsgid: self::int($r['reported_fd_msgid'] ?? null),
            subject: self::str($r['subject'] ?? null),
            text: (string) ($r['text'] ?? ''),
            displayName: self::str($r['display_name'] ?? null),
            automated: (bool) ($r['automated'] ?? false),
            emailLogId: self::str($r['email_log_id'] ?? null),
        );
    }

    /** The id the email TN sent for this message carries in X-Trash-Nothing-Log-Id. */
    public function logId(): string
    {
        return $this->emailLogId ?? $this->id;
    }

    /** Sort key: TN documents no order, so a window is written in (date, id) order (3.6). */
    public function sortKey(): string
    {
        return ($this->date !== null ? gmdate('Y-m-d\TH:i:s', strtotime($this->date) ?: 0) : '') . '|' . $this->id;
    }

    private static function int(mixed $v): ?int
    {
        return $v === null || $v === '' || (int) $v === 0 ? null : (int) $v;
    }

    private static function str(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : (string) $v;
    }
}
