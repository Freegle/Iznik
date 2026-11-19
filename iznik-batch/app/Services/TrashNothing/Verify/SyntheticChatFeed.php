<?php

namespace App\Services\TrashNothing\Verify;

use App\Models\Group;
use App\Services\Mail\Incoming\ParsedEmail;
use App\Services\TrashNothing\Ingestion\TnChatMessage;
use Carbon\CarbonImmutable;

/**
 * The record TN's chat-messages feed should give for a TN member's chat email, built from the
 * email itself: section 7.1 of plans/2026-11-12-tn-api-chat-ingestion.md, filled from what the
 * mail carries. For tn:chat-parity-check --feed=email, which tests everything on our side against
 * real mail before TN serves the feed, on the assumption that the feed says what the mail says.
 *
 * What it cannot test is TN: whether its ids, dates and fields really are these.
 */
final class SyntheticChatFeed
{
    public static function fromEmail(ParsedEmail $email, string $kind, CarbonImmutable $arrival): ?TnChatMessage
    {
        $logId = trim((string) $email->getHeader(ChatEmailInventory::HEADER_LOG_ID));
        if ($logId === '') {
            return null;
        }

        $fdMsgid = $email->getHeader('x-fd-msgid');
        $record = [
            'id' => $logId,
            // When TN sent it, as near as the mail says: its Date, else when it arrived.
            'date' => ($email->date?->copy() ?? $arrival)->utc()->format('Y-m-d\TH:i:s\Z'),
            'tn_user_id' => $email->getTrashNothingUserId() ?? 0,
            'kind' => $kind,
            'fd_msgid' => $fdMsgid !== null && ctype_digit(trim($fdMsgid)) ? (int) $fdMsgid : null,
            'subject' => $email->subject,
            'text' => self::text($email),
            'display_name' => $email->fromName,
            'automated' => false,
        ];

        switch ($kind) {
            case TnChatMessage::KIND_CHAT_REPLY:
                $record['fd_chat_id'] = $email->chatId;
                // The notify address names our id for the member TN replied as.
                $record['fd_user_id'] = $email->chatUserId;
                break;
            case TnChatMessage::KIND_POST_REPLY:
            case TnChatMessage::KIND_USER_MESSAGE:
                if (preg_match('/-(\d+)@/', $email->envelopeTo, $m)) {
                    $record['fd_recipient_user_id'] = (int) $m[1];
                }
                break;
            case TnChatMessage::KIND_VOLUNTEERS:
            case TnChatMessage::KIND_REPORT:
                $record['fd_group_id'] = $email->targetGroupName !== null
                    ? Group::where('nameshort', $email->targetGroupName)->value('id')
                    : null;
                break;
        }

        return TnChatMessage::fromArray($record);
    }

    /** The text part, or the HTML part made text, as createChatMessageFromEmail() reads it. */
    private static function text(ParsedEmail $email): string
    {
        if ($email->textBody !== null) {
            return $email->textBody;
        }

        return $email->htmlBody !== null ? (new \Html2Text\Html2Text($email->htmlBody))->getText() : '';
    }
}
