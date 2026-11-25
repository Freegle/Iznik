<?php

namespace App\Services\TrashNothing\Verify;

use App\Models\ChatMessage;
use App\Services\Mail\Incoming\IncomingArchiveReader;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\Mail\Incoming\ParsedEmail;
use App\Services\Mail\Incoming\RoutingResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The email side of tn:chat-parity-check and tn:verify-chat-coverage: every TN member's chat
 * email in a window, keyed by the X-Trash-Nothing-Log-Id TN puts on each one, with what the email
 * path made of it. See plans/2026-11-12-tn-api-chat-ingestion.md, 5.3 and 5.6.
 *
 * Two sources, which see different mail:
 *
 *   - the incoming archive keeps every email for 48 hours, dropped or not, with its outcome;
 *   - the database keeps, for about two days, the raw mail of each email that became a chat
 *     message (messages, linked by chat_messages_byemail). Mail the email path dropped left no
 *     row there. This is the source on yesterday, whose snapshot has no archive.
 *
 * Read-only. Never returns what a member wrote, only the chat message row, whose text the caller
 * hashes.
 */
class ChatEmailInventory
{
    public const HEADER_LOG_ID = 'x-trash-nothing-log-id';

    public function __construct(
        private readonly IncomingArchiveReader $archive,
        private readonly MailParserService $parser,
        private readonly TnEmailRoutingGate $gate,
    ) {}

    /**
     * @return array{entries: array<string, array>, stats: array<string, int|bool>}
     */
    public function fromArchive(CarbonImmutable $from, CarbonImmutable $to, bool $synthesize = false): array
    {
        $entries = [];
        $stats = ['archive_present' => $this->archive->exists(), 'files_scanned' => 0, 'files_unreadable' => 0, 'no_log_id' => 0, 'duplicates' => 0];

        if (! $stats['archive_present']) {
            Log::warning('TN chat parity: incoming archive directory missing', ['dir' => $this->archive->directory()]);

            return ['entries' => $entries, 'stats' => $stats];
        }

        foreach ($this->archive->recordsBetween($from, $to, $stats) as [$path, $record, $timestamp]) {
            $raw = $this->archive->rawEmail($record);
            // Cheap test before a full parse: most archived mail is not TN's.
            if ($raw === null || stripos($raw, 'trashnothing.com') === false) {
                continue;
            }

            try {
                $email = $this->archive->parse($raw, $record);
            } catch (\Throwable $e) {
                Log::warning('TN chat parity: could not parse archived email', ['path' => $path, 'error' => $e->getMessage()]);
                continue;
            }

            $outcome = isset($record['routing_outcome']) ? (string) $record['routing_outcome'] : null;
            $this->add($entries, $stats, $email, $timestamp, $outcome, fn () => $this->chatMessageForMessageId($email->messageId), $synthesize);
        }

        return ['entries' => $entries, 'stats' => $stats];
    }

    /**
     * From the raw mail the email path keeps beside each chat message it made. Every entry here
     * made a message; drops are not visible from the database.
     *
     * @return array{entries: array<string, array>, stats: array<string, int|bool>}
     */
    public function fromDatabase(CarbonImmutable $from, CarbonImmutable $to, bool $synthesize = false): array
    {
        $entries = [];
        $stats = ['rows' => 0, 'no_log_id' => 0, 'duplicates' => 0];
        $lastMsgId = null;

        DB::table('messages as m')
            ->join('chat_messages_byemail as cme', 'cme.msgid', '=', 'm.id')
            ->whereBetween('m.arrival', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->where('m.fromaddr', 'LIKE', '%trashnothing.com')
            ->where('m.message', '!=', '')
            ->select('m.id', 'm.message', 'm.envelopefrom', 'm.envelopeto', 'm.arrival', 'cme.chatmsgid')
            // One raw mail can be linked to more than one chat message; the first is the text.
            ->orderBy('m.id')
            ->orderBy('cme.chatmsgid')
            ->chunk(500, function ($rows) use (&$entries, &$stats, &$lastMsgId, $synthesize) {
                foreach ($rows as $row) {
                    if ($row->id === $lastMsgId) {
                        continue;
                    }
                    $lastMsgId = $row->id;
                    $stats['rows']++;

                    try {
                        $email = $this->parser->parse((string) $row->message, (string) $row->envelopefrom, (string) $row->envelopeto);
                    } catch (\Throwable) {
                        continue;
                    }

                    $chatMessage = $this->chatMessageRow((int) $row->chatmsgid);
                    $outcome = $chatMessage === null ? null
                        : ($chatMessage['chat_type'] === 'User2Mod' ? RoutingResult::TO_VOLUNTEERS->value : RoutingResult::TO_USER->value);

                    $this->add($entries, $stats, $email, CarbonImmutable::parse($row->arrival, 'UTC'), $outcome, fn () => $chatMessage, $synthesize);
                }
            });

        return ['entries' => $entries, 'stats' => $stats];
    }

    /**
     * When the oldest and newest chat mail the database still keeps arrived. purge:messages takes
     * a chat email's raw copy after about two days, so a window outside this finds nothing.
     *
     * @return array{0: string, 1: string}|null
     */
    public function databaseChatMailRange(): ?array
    {
        $kept = fn () => DB::table('messages as m')->join('chat_messages_byemail as cme', 'cme.msgid', '=', 'm.id');
        $first = $kept()->min('m.arrival');

        return $first !== null ? [(string) $first, (string) $kept()->max('m.arrival')] : null;
    }

    /**
     * The chat message the email path made from the email with this Message-ID: its raw copy is in
     * messages, linked by chat_messages_byemail. Null when it made none, or the copy has been
     * purged (after two days).
     */
    public function chatMessageForMessageId(?string $messageId): ?array
    {
        if ($messageId === null || $messageId === '') {
            return null;
        }

        $chatMsgId = DB::table('messages as m')
            ->join('chat_messages_byemail as cme', 'cme.msgid', '=', 'm.id')
            ->where('m.messageid', $messageId)
            ->min('cme.chatmsgid');

        return $chatMsgId !== null ? $this->chatMessageRow((int) $chatMsgId) : null;
    }

    /** The fields of a chat message the parity check compares, with its chat's type and members. */
    public function chatMessageRow(int $chatMessageId): ?array
    {
        $row = DB::table('chat_messages as cm')
            ->join('chat_rooms as cr', 'cr.id', '=', 'cm.chatid')
            ->where('cm.id', $chatMessageId)
            ->where('cm.type', '!=', ChatMessage::TYPE_IMAGE)
            ->first(['cm.id', 'cm.chatid', 'cm.userid', 'cm.type', 'cm.refmsgid', 'cm.message', 'cm.reviewrequired',
                'cm.reportreason', 'cr.chattype', 'cr.user1', 'cr.user2', 'cr.groupid']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'chat_id' => (int) $row->chatid,
            'sender_id' => (int) $row->userid,
            'chat_type' => $row->chattype,
            'type' => $row->type,
            'refmsgid' => $row->refmsgid !== null ? (int) $row->refmsgid : null,
            'message' => (string) $row->message,
            'review_required' => (bool) $row->reviewrequired,
            'report_reason' => $row->reportreason,
            'user1' => $row->user1 !== null ? (int) $row->user1 : null,
            'user2' => $row->user2 !== null ? (int) $row->user2 : null,
            'group_id' => $row->groupid !== null ? (int) $row->groupid : null,
        ];
    }

    /**
     * @param  callable(): ?array  $chatMessage  Looked up only for TN chat mail.
     * @param  bool  $synthesize  Also give each entry the record TN's feed should give for it (SyntheticChatFeed).
     */
    private function add(array &$entries, array &$stats, ParsedEmail $email, CarbonImmutable $timestamp, ?string $outcome, callable $chatMessage, bool $synthesize = false): void
    {
        $kind = $this->gate->trashNothingChatKind($email);
        if ($kind === null) {
            return;
        }

        $logId = trim((string) $email->getHeader(self::HEADER_LOG_ID));
        if ($logId === '') {
            $stats['no_log_id']++;

            return;
        }

        if (isset($entries[$logId])) {
            $stats['duplicates']++;

            return;
        }

        $entries[$logId] = [
            'log_id' => $logId,
            'message_id' => $email->messageId,
            'kind' => $kind,
            'timestamp' => $timestamp->format('Y-m-d\TH:i:s\Z'),
            'outcome' => $outcome,
            'chat_message' => $chatMessage(),
        ];

        if ($synthesize) {
            $entries[$logId]['record'] = SyntheticChatFeed::fromEmail($email, $kind, $timestamp);
        }
    }
}
