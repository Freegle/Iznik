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
            $this->add($entries, $stats, $email, $timestamp, $outcome, fn () => $this->chatMessagesForMessageId($email->messageId), $synthesize);
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

                    $linked = $this->chatMessagesForRaw((int) $row->id);
                    $chatMessage = $linked[0] ?? null;
                    $outcome = $chatMessage === null ? null
                        : ($chatMessage['chat_type'] === 'User2Mod' ? RoutingResult::TO_VOLUNTEERS->value : RoutingResult::TO_USER->value);

                    $this->add($entries, $stats, $email, CarbonImmutable::parse($row->arrival, 'UTC'), $outcome, fn () => $linked, $synthesize);
                }
            });

        return ['entries' => $entries, 'stats' => $stats];
    }

    /**
     * Where the chat mail the database still keeps begins and ends. purge:messages takes a chat
     * email's raw copy after about two days, so a window outside this finds nothing. A few far
     * older copies survive the purge (production has some from 2017), so the start is looked for
     * only in the three days before the newest, which covers the two the purge keeps.
     *
     * @return array{0: string, 1: string}|null
     */
    public function databaseChatMailRange(): ?array
    {
        $kept = fn () => DB::table('messages as m')->join('chat_messages_byemail as cme', 'cme.msgid', '=', 'm.id');
        $last = $kept()->max('m.arrival');
        if ($last === null) {
            return null;
        }

        $first = $kept()->where('m.arrival', '>=', CarbonImmutable::parse((string) $last, 'UTC')->subDays(3)->toDateTimeString())->min('m.arrival');

        return [(string) $first, (string) $last];
    }

    /**
     * The chat messages the email path made from the email with this Message-ID: its raw copy is
     * in messages, linked by chat_messages_byemail. Empty when it made none, or the copy has been
     * purged (after two days).
     *
     * @return list<array>
     */
    public function chatMessagesForMessageId(?string $messageId): array
    {
        if ($messageId === null || $messageId === '') {
            return [];
        }

        $rawId = DB::table('messages')->where('messageid', $messageId)->value('id');

        return $rawId !== null ? $this->chatMessagesForRaw((int) $rawId) : [];
    }

    /**
     * The text chat messages linked to one raw mail, oldest first.
     *
     * @return list<array>
     */
    public function chatMessagesForRaw(int $rawId): array
    {
        $ids = DB::table('chat_messages_byemail')->where('msgid', $rawId)->orderBy('chatmsgid')->pluck('chatmsgid');

        return array_values(array_filter($ids->map(fn ($id) => $this->chatMessageRow((int) $id))->all()));
    }

    /** The fields of a chat message the parity check compares, with its chat's type and members. */
    public function chatMessageRow(int $chatMessageId): ?array
    {
        $row = DB::table('chat_messages as cm')
            ->join('chat_rooms as cr', 'cr.id', '=', 'cm.chatid')
            ->where('cm.id', $chatMessageId)
            ->where('cm.type', '!=', ChatMessage::TYPE_IMAGE)
            ->first(['cm.id', 'cm.chatid', 'cm.userid', 'cm.type', 'cm.refmsgid', 'cm.message', 'cm.reviewrequired',
                'cm.reportreason', 'cm.reviewedby', 'cm.reviewrejected', 'cm.date', 'cr.chattype', 'cr.user1', 'cr.user2', 'cr.groupid']);

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
            // A moderator approved or rejected it since, which clears the hold it arrived with.
            'reviewed_by' => $row->reviewedby !== null ? (int) $row->reviewedby : null,
            // Rejected since: by a moderator, or by a block keyword when chats:process-incoming
            // reached it, which leaves no reviewer (ChatProcessService::dropBlocked).
            'review_rejected' => (bool) $row->reviewrejected,
            'date' => $row->date !== null ? CarbonImmutable::parse((string) $row->date, 'UTC') : null,
            'user1' => $row->user1 !== null ? (int) $row->user1 : null,
            'user2' => $row->user2 !== null ? (int) $row->user2 : null,
            'group_id' => $row->groupid !== null ? (int) $row->groupid : null,
        ];
    }

    /**
     * @param  callable(): list<array>  $chatMessages  The chat messages linked to the mail, looked up only for TN chat mail.
     * @param  bool  $synthesize  Also give each entry the record TN's feed should give for it (SyntheticChatFeed).
     */
    private function add(array &$entries, array &$stats, ParsedEmail $email, CarbonImmutable $timestamp, ?string $outcome, callable $chatMessages, bool $synthesize = false): void
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

        $linked = $chatMessages();
        $entries[$logId] = [
            'log_id' => $logId,
            'message_id' => $email->messageId,
            'kind' => $kind,
            'timestamp' => $timestamp->format('Y-m-d\TH:i:s\Z'),
            'outcome' => $outcome,
            'chat_message' => $linked[0] ?? null,
            // Usually one. More when another mail had the same Message-ID: the email path then links
            // its chat message to the raw copy already stored (storeChatEmailMessage).
            'linked' => $linked,
        ];

        if ($synthesize) {
            $entries[$logId]['record'] = SyntheticChatFeed::fromEmail($email, $kind, $timestamp);
        }
    }
}
