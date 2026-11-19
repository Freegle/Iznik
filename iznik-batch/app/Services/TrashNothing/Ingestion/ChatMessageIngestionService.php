<?php

namespace App\Services\TrashNothing\Ingestion;

use App\Models\ChatMessage;
use App\Models\ChatMessageTn;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\User;
use App\Services\Mail\Incoming\ParsedEmail;
use App\Services\Mail\Incoming\RoutingResult;
use App\Services\Mail\Incoming\SpamCheckService;
use App\Services\Mail\Incoming\StripQuotedService;
use App\Services\Ripple\RippleReplyService;
use App\Services\TrashNothing\Sync\TnMemberMatch;
use App\Support\TransactionPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingests a record of TrashNothing's chat-messages feed, mirroring what the email path does with
 * the email TN sends for the same message: IncomingMailService's handleChatNotificationReply,
 * handleDirectMail and handleVolunteersMessage, and createChatMessageFromEmail, which they share.
 *
 * The email path is never touched. Logic here is duplicated, not extracted, until parity is
 * proven, as GroupPostIngestionService does for posts, and EmailPathMirrorDriftTest pins the
 * email methods so an edit to them forces a look at this one. Where this path follows a different
 * rule on purpose, the rule is named where it is applied (plan section 3):
 *
 *   - the sender is the account holding the TN user id, never one found by address;
 *   - the chat and the post come from ids, and there is no fuzzy subject match;
 *   - no address is attached to the sender's account;
 *   - TN's own notices to volunteers are not carried yet.
 *
 * plan() decides and reads only; apply() writes. tn:chat-parity-check runs plan() against what the
 * email path wrote, so plan() must not write anything, and must give the same answer twice. Like
 * the posts service it never logs to Loki: its caller writes one routed entry per record.
 */
class ChatMessageIngestionService
{
    // routing_reason values byte-identical to the email path's, so the two paths' Loki entries
    // and the parity check can group on them.
    public const REASON_NO_CHAT = 'Reply to non-existent chat';

    public const REASON_CLOSED_TO_MODERATOR = 'Moderator reply to a chat closed to moderators';

    public const REASON_UNKNOWN_RECIPIENT = 'Direct mail to unknown user address';

    public const REASON_TO_SELF = 'Direct mail to self';

    public const REASON_UNKNOWN_GROUP = 'Volunteers message to unknown group';

    public const REASON_DELETED_SENDER = 'Volunteers message from deleted user';

    public const REASON_KNOWN_SPAMMER = 'Known spammer';

    public const REASON_NO_USER_CHAT = 'Could not create chat for direct mail';

    public const REASON_NO_USER2MOD_CHAT = 'Could not create User2Mod chat';

    // API-only routing_reason values. Stable strings: the parity check and dashboards group on them.

    /** No account holds the sender's TN user id, and none TN names may be used (plan 3.1). */
    public const REASON_SENDER_UNKNOWN = 'tn-sender-unknown';

    /** The account holding the sender's TN user id is not in the chat TN named (plan 3.1). */
    public const REASON_SENDER_NOT_IN_CHAT = 'tn-sender-not-in-chat';

    /** The record lacks a field its kind needs: the missing one is in the context. */
    public const REASON_INCOMPLETE = 'tn-incomplete-record';

    /** TN's own notice to volunteers, which stays on email for now (decision 7). */
    public const REASON_NOTICE_NOT_CARRIED = 'tn-notice-not-carried';

    public const REASON_UNKNOWN_KIND = 'tn-unknown-kind';

    public const REASON_DUPLICATE = 'duplicate';

    /** The value trackEmailReply() records on the email path is 'email'. */
    public const REPLIED_VIA = 'tn_api';

    /** chat_messages.reportreason enum values, as IncomingMailService::mapReportReason(). */
    private const REPORT_REASONS = [
        'Spam', 'Other', 'Last', 'Force', 'Fully', 'TooMany', 'User',
        'UnknownMessage', 'SameImage', 'DodgyImage',
        'CountryBlocked',
        'SubjectUsedForDifferentGroups',
        'SpamAssassin',
        'Greetings spam',
        'Referenced known spammer',
        'Known spam keyword',
        'URL on DBL',
        'BulkVolunteerMail',
        'UsedOurDomain',
        'WorryWord',
        'Script',
        'Link',
        'Money',
        'Email',
        'Language',
    ];

    private SpamCheckService $spamCheck;

    private StripQuotedService $stripQuoted;

    public function __construct(
        private readonly bool $dryRun = false,
        ?SpamCheckService $spamCheck = null,
        ?StripQuotedService $stripQuoted = null,
    ) {
        $this->spamCheck = $spamCheck ?? app(SpamCheckService::class);
        $this->stripQuoted = $stripQuoted ?? new StripQuotedService;
    }

    /**
     * Decide what to do with a record, writing nothing.
     *
     * @param  int|null  $asOfChatMessageId  For the parity check: the email path's copy of this
     *                                       message. Reads of the chat's history look only at
     *                                       messages before it, as they stood when it arrived.
     */
    public function plan(TnChatMessage $record, ?int $asOfChatMessageId = null): ChatIngestPlan
    {
        if (ChatMessageTn::where('tnmessageid', $record->id)->exists()) {
            return ChatIngestPlan::notWritten($record, ChatIngestPlan::RESULT_DUPLICATE, self::REASON_DUPLICATE);
        }

        return match ($record->kind) {
            TnChatMessage::KIND_CHAT_REPLY => $this->planChatReply($record, $asOfChatMessageId),
            TnChatMessage::KIND_POST_REPLY, TnChatMessage::KIND_USER_MESSAGE => $this->planDirect($record, $asOfChatMessageId),
            TnChatMessage::KIND_VOLUNTEERS, TnChatMessage::KIND_REPORT => $this->planVolunteers($record, $asOfChatMessageId),
            TnChatMessage::KIND_TN_NOTICE => ChatIngestPlan::notWritten($record, ChatIngestPlan::RESULT_SKIPPED, self::REASON_NOTICE_NOT_CARRIED),
            default => ChatIngestPlan::notWritten($record, ChatIngestPlan::RESULT_SKIPPED, self::REASON_UNKNOWN_KIND, ['kind' => $record->kind]),
        };
    }

    /**
     * Row 1, a reply in an existing chat: handleChatNotificationReply.
     *
     * Not mirrored, because they are about mail: misdirected read receipts, bounces and
     * auto-replies. Replaced: the stale-chat check on the sender's address, by the TN id rule.
     */
    private function planChatReply(TnChatMessage $r, ?int $asOf): ChatIngestPlan
    {
        if ($r->fdChatId === null) {
            return $this->incomplete($r, 'fd_chat_id');
        }

        // Follow redirects from merged duplicate rooms.
        $chat = ChatRoom::find($r->fdChatId);
        if ($chat === null) {
            $redirect = DB::table('chat_room_redirects')->where('old_id', $r->fdChatId)->first();
            if ($redirect) {
                $chat = ChatRoom::find($redirect->new_id);
            }
        }
        if ($chat === null) {
            return ChatIngestPlan::notWritten($r, ChatIngestPlan::RESULT_DROPPED, self::REASON_NO_CHAT);
        }

        $sender = $this->sender($r);
        if ($sender === null) {
            return $this->senderUnknown($r, ['chat_id' => $r->fdChatId]);
        }

        // The email path checks that the user its address names is in the chat. The sender is
        // decided by TN id here instead, and it must be the one in the chat.
        if (!$this->isUserInChat($sender->id, $chat)) {
            return ChatIngestPlan::notWritten($r, ChatIngestPlan::RESULT_SKIPPED, self::REASON_SENDER_NOT_IN_CHAT, [
                'user_id' => $sender->id,
                'chat_id' => $chat->id,
            ]);
        }

        // Never fires for a TN sender, who cannot be a moderator, but kept so the rule has one
        // shape on both paths.
        if ($this->chatIsClosedToModerator($chat, $sender->id)) {
            return ChatIngestPlan::notWritten($r, ChatIngestPlan::RESULT_DROPPED, self::REASON_CLOSED_TO_MODERATOR, [
                'user_id' => $sender->id,
                'chat_id' => $chat->id,
            ]);
        }

        // The email path's chat reply runs the spam check as a chat reply, with no subject checks.
        $email = $this->asParsedEmail($r, $sender, false);

        return $this->writePlan(
            $r,
            RoutingResult::TO_USER,
            // The email path's context names the chat from the address, before any redirect.
            ['user_id' => $sender->id, 'chat_id' => $r->fdChatId],
            $chat->id,
            $chat->chattype,
            $sender,
            text: $this->text($r, null, false),
            spam: $this->spamCheck->checkMessage($email, forChatReply: true),
            asOf: $asOf,
            trackChatId: $r->fdChatId,
            touchLastAccess: true,
        );
    }

    /**
     * Rows 2 and 3, a message to a Freegle member, about a post or not: handleDirectMail. The
     * recipient comes from the id TN gives, which is the id in the address the email goes to; the
     * post only from fd_msgid, with no subject match (plan 3.2).
     */
    private function planDirect(TnChatMessage $r, ?int $asOf): ChatIngestPlan
    {
        $refMsgId = null;
        $postFrom = null;
        if ($r->fdMsgid !== null) {
            // As findRefMessage(): the post only has to exist.
            $post = DB::table('messages')->where('id', $r->fdMsgid)->first(['id', 'fromuser']);
            if ($post !== null) {
                $refMsgId = (int) $post->id;
                $postFrom = $post->fromuser !== null ? (int) $post->fromuser : null;
            }
        }

        $recipientId = $r->fdRecipientUserId ?? $postFrom;
        if ($recipientId === null && $r->fdMsgid === null) {
            return $this->incomplete($r, 'fd_recipient_user_id');
        }

        // In the email path's order: route() drops a known spammer before Phase 6 reaches
        // handleDirectMail, which looks for the recipient before the sender.
        $sender = $this->sender($r);
        if ($sender !== null && $this->isKnownSpammer($sender->id)) {
            return ChatIngestPlan::notWritten($r, ChatIngestPlan::RESULT_DROPPED, self::REASON_KNOWN_SPAMMER);
        }

        $recipient = $recipientId !== null ? User::find($recipientId) : null;
        if ($recipient === null) {
            return ChatIngestPlan::notWritten($r, ChatIngestPlan::RESULT_DROPPED, self::REASON_UNKNOWN_RECIPIENT);
        }

        if ($sender === null) {
            return $this->senderUnknown($r);
        }

        if ($sender->id === $recipient->id) {
            return ChatIngestPlan::notWritten($r, ChatIngestPlan::RESULT_DROPPED, self::REASON_TO_SELF);
        }

        $chat = $this->findUserChat($sender->id, $recipient->id);

        // #20 on the email path: the subject goes in front of a message not tied to a post.
        $prependSubject = ($refMsgId === null && !empty($r->subject)) ? $r->subject : null;

        return $this->writePlan(
            $r,
            RoutingResult::TO_USER,
            ['user_id' => $sender->id, 'to_user_id' => $recipient->id, 'chat_id' => $chat?->id],
            $chat?->id,
            ChatRoom::TYPE_USER2USER,
            $sender,
            recipientId: $recipient->id,
            type: $refMsgId !== null ? ChatMessage::TYPE_INTERESTED : ChatMessage::TYPE_DEFAULT,
            refMsgId: $refMsgId,
            text: $this->text($r, $prependSubject, false),
            spam: $this->spamCheck->checkMessage($this->asParsedEmail($r, $sender, false), forChatReply: true),
            asOf: $asOf,
            trackChatId: $chat?->id,
        );
    }

    /**
     * Rows 4 and 5, to a community's volunteers, and reports: handleVolunteersMessage. A spam
     * finding holds the message for review and never drops it, because members report spam to
     * volunteers. SpamAssassin is not run: there is no mail for it to read.
     */
    private function planVolunteers(TnChatMessage $r, ?int $asOf): ChatIngestPlan
    {
        if ($r->fdGroupId === null) {
            return $this->incomplete($r, 'fd_group_id');
        }

        // In the email path's order: route() drops a known spammer before Phase 4 reaches
        // handleVolunteersMessage, which looks for the community before the sender.
        $sender = $this->sender($r);
        if ($sender !== null && $this->isKnownSpammer($sender->id)) {
            return ChatIngestPlan::notWritten($r, ChatIngestPlan::RESULT_DROPPED, self::REASON_KNOWN_SPAMMER);
        }

        $group = Group::find($r->fdGroupId);
        if ($group === null) {
            return ChatIngestPlan::notWritten($r, ChatIngestPlan::RESULT_DROPPED, self::REASON_UNKNOWN_GROUP);
        }

        if ($sender === null) {
            return $this->senderUnknown($r);
        }

        if ($sender->deleted !== null) {
            return ChatIngestPlan::notWritten($r, ChatIngestPlan::RESULT_DROPPED, self::REASON_DELETED_SENDER);
        }

        $chatId = DB::table('chat_rooms')
            ->where('user1', $sender->id)
            ->where('groupid', $group->id)
            ->where('chattype', ChatRoom::TYPE_USER2MOD)
            ->value('id');

        // A TN report carries a transcript, which stripping would eat.
        $isReport = $r->kind === TnChatMessage::KIND_REPORT || str_starts_with($r->subject ?? '', 'Reporting ');

        return $this->writePlan(
            $r,
            RoutingResult::TO_VOLUNTEERS,
            [
                'group_id' => $group->id,
                'group_name' => $group->nameshort ?? $group->namefull ?? '',
                'user_id' => $sender->id,
                'chat_id' => $chatId !== null ? (int) $chatId : null,
            ],
            $chatId !== null ? (int) $chatId : null,
            ChatRoom::TYPE_USER2MOD,
            $sender,
            groupId: $group->id,
            text: $this->text($r, null, $isReport),
            // Without forChatReply: the subject checks run on mail to volunteers.
            spam: $this->spamCheck->checkMessage($this->asParsedEmail($r, $sender, true, $group)),
            asOf: $asOf,
            touchLastAccess: true,
        );
    }

    /**
     * The review decision and the row, as createChatMessageFromEmail() makes them: a spam finding,
     * then checkReview() on the text, then "the last message in this chat is held".
     *
     * @param  array{0: bool, 1: string, 2: string}|null  $spam
     */
    private function writePlan(
        TnChatMessage $r,
        RoutingResult $outcome,
        array $context,
        ?int $chatId,
        string $chatType,
        User $sender,
        string $text,
        ?array $spam,
        ?int $asOf,
        ?int $recipientId = null,
        ?int $groupId = null,
        string $type = ChatMessage::TYPE_DEFAULT,
        ?int $refMsgId = null,
        ?int $trackChatId = null,
        bool $touchLastAccess = false,
    ): ChatIngestPlan {
        $reviewRequired = false;
        $reportReason = null;

        if ($spam !== null) {
            $reviewRequired = true;
            $reportReason = $spam[1];
        }

        if (!$reviewRequired && strlen($text) > 0) {
            $reviewReason = $this->spamCheck->checkReview($text, true);
            if ($reviewReason !== null) {
                $reviewRequired = true;
                $reportReason = $reviewReason;
            }
        }

        if (!$reviewRequired && $chatId !== null && $this->lastMessageHeld($chatId, $asOf)) {
            $reviewRequired = true;
            $reportReason = 'Last';
        }

        return new ChatIngestPlan(
            record: $r,
            result: ChatIngestPlan::RESULT_WRITE,
            outcome: $outcome,
            context: $context,
            chatId: $chatId,
            chatType: $chatType,
            senderId: $sender->id,
            recipientId: $recipientId,
            groupId: $groupId,
            messageType: $type,
            refmsgid: $refMsgId,
            text: $text,
            reviewRequired: $reviewRequired,
            reportReason: self::mapReportReason($reportReason),
            trackChatId: $trackChatId,
            touchLastAccess: $touchLastAccess,
        );
    }

    /**
     * Write what plan() decided: the chat if it does not exist yet, the chat message and its
     * chat_messages_tn row in one transaction, then what the email handler does after writing.
     * The unique key on TN's id decides a race, and a lost race is a duplicate, not an error.
     */
    public function apply(ChatIngestPlan $plan): ChatIngestPlan
    {
        if ($plan->result !== ChatIngestPlan::RESULT_WRITE) {
            return $plan;
        }

        if ($this->dryRun) {
            return $plan;
        }

        $record = $plan->record;

        try {
            [$chatId, $chatMessageId] = TransactionPolicy::atomic(function () use ($plan, $record) {
                $chatId = $this->chatFor($plan);
                if ($chatId === null) {
                    return [null, null];
                }

                $message = ChatMessage::create([
                    'chatid' => $chatId,
                    'userid' => $plan->senderId,
                    'message' => $plan->text,
                    'type' => $plan->messageType,
                    'refmsgid' => $plan->refmsgid,
                    // The time of ingestion, never TN's date: the notifier admits replies by date
                    // inside a four-hour look-back (plan 3.5).
                    'date' => now(),
                    // As email: ChatProcessService marks it seen and mailed for its sender.
                    'platform' => 0,
                    'reviewrequired' => $plan->reviewRequired ? 1 : 0,
                    'reportreason' => $plan->reportReason,
                    'processingrequired' => 1,
                    'replyreceived' => 0,
                ]);

                ChatMessageTn::create([
                    'chatmsgid' => $message->id,
                    'tnmessageid' => $record->id,
                    'tndate' => $record->date !== null ? gmdate('Y-m-d H:i:s', strtotime($record->date) ?: time()) : null,
                ]);

                return [$chatId, (int) $message->id];
            }, 'TN chat message and the record of which feed record it came from');
        } catch (UniqueConstraintViolationException) {
            return $plan->withResult(ChatIngestPlan::RESULT_DUPLICATE, self::REASON_DUPLICATE);
        }

        if ($chatId === null) {
            return $plan->withResult(ChatIngestPlan::RESULT_DROPPED, $plan->chatType === ChatRoom::TYPE_USER2MOD
                ? self::REASON_NO_USER2MOD_CHAT
                : self::REASON_NO_USER_CHAT);
        }

        if ($plan->touchLastAccess) {
            DB::table('users')->where('id', $plan->senderId)->update(['lastaccess' => now()]);
        }

        $sender = User::find($plan->senderId);

        // The rippling hold, on a reply tied to a post, as handleDirectMail does for TN mail.
        if ($plan->refmsgid !== null && $sender !== null) {
            $this->holdReplyIfOutsideReach($chatId, $chatMessageId, $plan->refmsgid, $sender);
        }

        // Only the member handlers mark the notification replied; mail to volunteers does not.
        if ($plan->outcome === RoutingResult::TO_USER) {
            $this->trackReply($plan->trackChatId ?? $chatId, $plan->senderId);
        }

        $context = $plan->context;
        if (array_key_exists('chat_id', $context) && $context['chat_id'] === null) {
            $context['chat_id'] = $chatId;
        }

        return $plan->withWritten($chatId, $chatMessageId, $context);
    }

    /** The chat to write into, made as the email handler would make it when there is none. */
    private function chatFor(ChatIngestPlan $plan): ?int
    {
        if ($plan->chatType === ChatRoom::TYPE_USER2MOD && $plan->groupId !== null) {
            // As handleVolunteersMessage: this also brings latestmessage and the roster up to date.
            return ChatRoom::getOrCreateUser2Mod($plan->senderId, $plan->groupId)?->id;
        }

        if ($plan->chatId !== null) {
            return $plan->chatId;
        }

        if ($plan->recipientId === null) {
            return null;
        }

        return $this->getOrCreateUserChat($plan->senderId, $plan->recipientId)?->id;
    }

    /** IncomingMailService::getOrCreateUserChat(). */
    private function getOrCreateUserChat(int $userId1, int $userId2): ?ChatRoom
    {
        $chat = $this->findUserChat($userId1, $userId2);
        if ($chat !== null) {
            return $chat;
        }

        if ($userId1 > $userId2) {
            [$userId1, $userId2] = [$userId2, $userId1];
        }

        $chat = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $userId1,
            'user2' => $userId2,
        ]);

        // Without roster rows the members are never mailed about the chat.
        DB::table('chat_roster')->insertOrIgnore(['chatid' => $chat->id, 'userid' => $userId1]);
        DB::table('chat_roster')->insertOrIgnore(['chatid' => $chat->id, 'userid' => $userId2]);

        return $chat;
    }

    /** IncomingMailService::trackEmailReply(), recording that the reply came by the TN API. */
    private function trackReply(int $chatId, int $userId): void
    {
        try {
            DB::table('email_tracking')
                ->where('email_type', 'ChatNotification')
                ->where('userid', $userId)
                ->where('replied_at', null)
                ->whereJsonContains('metadata->chat_id', $chatId)
                ->orderByDesc('sent_at')
                ->limit(1)
                ->update([
                    'replied_at' => now(),
                    'replied_via' => self::REPLIED_VIA,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to track TN chat reply', ['chat_id' => $chatId, 'user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /** IncomingMailService::holdReplyIfOutsideReach(), with the source TN mail gives it. */
    private function holdReplyIfOutsideReach(int $chatId, int $chatMsgId, int $msgid, User $replier): void
    {
        $latlng = $this->resolveReplierLatLng($replier);
        if ($latlng === null) {
            return;
        }

        [$lat, $lng] = $latlng;
        $service = app(RippleReplyService::class);
        $band = $replier->settings['browseDensityBand'] ?? null;

        if ($service->shouldHold($msgid, $lat, $lng, is_string($band) ? $band : null)) {
            $service->hold($chatId, $chatMsgId, $msgid, $replier->id, $lat, $lng, 'tn');
            Log::info('ripple:held-external-reply', [
                'msgid' => $msgid,
                'chatid' => $chatId,
                'chatmsgid' => $chatMsgId,
                'replieruserid' => $replier->id,
                'source' => 'tn',
            ]);
        }
    }

    /** IncomingMailService::resolveReplierLatLng(). */
    private function resolveReplierLatLng(User $replier): ?array
    {
        $chosen = User::chosenLatLng($replier->settings, $replier->tnuserid);
        if ($chosen) {
            return $chosen;
        }

        if ($replier->lastlocation) {
            $loc = DB::table('locations')->where('id', $replier->lastlocation)->first(['lat', 'lng']);
            if ($loc && $loc->lat !== null && $loc->lng !== null) {
                return [(float) $loc->lat, (float) $loc->lng];
            }
        }

        return null;
    }

    /**
     * The body createChatMessageFromEmail() stores: the subject in front when asked, the digest
     * reply label, and quoted text and signatures stripped unless kept for a report. TN sends the
     * text part of its email, which Q9 found carries no quoting, so stripping takes little; it is
     * run so whatever TN adds is treated alike, including its automated-response note (row 6).
     */
    private function text(TnChatMessage $r, ?string $prependSubject, bool $skipStripQuoted): string
    {
        $body = $r->text;

        if ($prependSubject !== null) {
            $body = $prependSubject . "\r\n\r\n" . $body;
        }

        $isDigestReply = false;
        if (!$skipStripQuoted) {
            $groupDomain = preg_quote(config('freegle.mail.group_domain', 'groups.ilovefreegle.org'), '/');
            if (preg_match('/^\s*On.*?-auto@' . $groupDomain . '>\s*wrote\s*:/ms', $body) ||
                preg_match('/-----Original Message-----/', $body)) {
                $isDigestReply = true;
            }

            $body = $this->stripQuoted->strip($body);
        }

        if ($isDigestReply) {
            $body = rtrim($body) . "\r\n\r\n(Probably replied to digest - check View original email)";
        }

        return $body;
    }

    /**
     * The spam check reads a ParsedEmail. This is the one TN would have sent, as far as the check
     * looks at it: the display name, subject and text, a TN sender (so the IP checks are skipped,
     * as for all TN mail), and the address it went to for the volunteers' subject checks.
     */
    private function asParsedEmail(TnChatMessage $r, User $sender, bool $toVolunteers, ?Group $group = null): ParsedEmail
    {
        $from = $this->tnAddress($sender);
        $to = $toVolunteers && $group !== null
            ? $group->nameshort . '-volunteers@' . config('freegle.mail.group_domain', 'groups.ilovefreegle.org')
            : '';

        return new ParsedEmail(
            rawMessage: '',
            envelopeFrom: $from,
            envelopeTo: $to,
            subject: $r->subject,
            fromAddress: $from,
            fromName: $r->displayName,
            toAddresses: $to !== '' ? [$to] : [],
            messageId: null,
            date: null,
            textBody: $r->text,
            htmlBody: null,
            headers: [],
            targetGroupName: $group?->nameshort,
            isToVolunteers: $toVolunteers,
            isToAuto: false,
            bounceRecipient: null,
            bounceStatus: null,
            bounceDiagnostic: null,
            chatId: null,
            chatUserId: null,
            chatMessageId: null,
            commandUserId: null,
            commandGroupId: null,
            senderIp: null,
        );
    }

    /**
     * The sender's TN address, which the email comes from: the preferred one if it is TN's, else
     * any. A member with none still reads as a TN sender, so the IP checks stay skipped.
     */
    private function tnAddress(User $sender): string
    {
        $suffix = '@user.' . config('freegle.mail.trashnothing_domain', 'trashnothing.com');
        $address = DB::table('users_emails')
            ->where('userid', $sender->id)
            ->where('email', 'LIKE', '%' . $suffix)
            ->orderByDesc('preferred')
            ->orderBy('id')
            ->value('email');

        return $address ?? ('noreply@' . config('freegle.mail.trashnothing_domain', 'trashnothing.com'));
    }

    /** The account TN means (plan 3.1): the one holding the TN id, else the one TN names, unless it holds another. */
    private function sender(TnChatMessage $r): ?User
    {
        return TnMemberMatch::find($r->fdUserId ?? 0, $r->tnUserId)->account();
    }

    private function senderUnknown(TnChatMessage $r, array $extra = []): ChatIngestPlan
    {
        return ChatIngestPlan::notWritten($r, ChatIngestPlan::RESULT_SKIPPED, self::REASON_SENDER_UNKNOWN, array_merge([
            'tn_user_id' => $r->tnUserId ?: null,
        ], $extra));
    }

    private function incomplete(TnChatMessage $r, string $field): ChatIngestPlan
    {
        return ChatIngestPlan::notWritten($r, ChatIngestPlan::RESULT_SKIPPED, self::REASON_INCOMPLETE, ['missing' => $field]);
    }

    /** As the email path's "last message in this chat is held, so hold this one", as of $asOf. */
    private function lastMessageHeld(int $chatId, ?int $asOf): bool
    {
        $query = DB::table('chat_messages')->where('chatid', $chatId);
        if ($asOf !== null) {
            $query->where('id', '<', $asOf);
        }

        return (bool) $query->orderByDesc('id')->limit(1)->value('reviewrequired');
    }

    /** IncomingMailService::isKnownSpammer(), on the account rather than the address it finds. */
    private function isKnownSpammer(int $userId): bool
    {
        return DB::table('spam_users')
            ->where('userid', $userId)
            ->where('collection', 'Spammer')
            ->exists();
    }

    /** IncomingMailService::isUserInChat(). */
    private function isUserInChat(int $userId, ChatRoom $chat): bool
    {
        if ($chat->chattype === ChatRoom::TYPE_USER2USER) {
            return $chat->user1 === $userId || $chat->user2 === $userId;
        }

        return DB::table('chat_roster')
            ->where('chatid', $chat->id)
            ->where('userid', $userId)
            ->exists();
    }

    /** IncomingMailService::chatIsClosedToModerator(). */
    private function chatIsClosedToModerator(ChatRoom $chat, int $writerId): bool
    {
        if ($chat->chattype !== ChatRoom::TYPE_USER2MOD || $chat->user1 === null || (int) $chat->user1 === $writerId) {
            return false;
        }

        $member = User::find($chat->user1);
        if ($member === null || $member->modsMayContact((int) $chat->groupid)) {
            return false;
        }

        return !User::find($writerId)?->isSupportOrAdmin();
    }

    /** The finding half of IncomingMailService::getOrCreateUserChat(): either user order. */
    private function findUserChat(int $userId1, int $userId2): ?ChatRoom
    {
        return ChatRoom::where('chattype', ChatRoom::TYPE_USER2USER)
            ->where(function ($q) use ($userId1, $userId2) {
                $q->where(function ($q2) use ($userId1, $userId2) {
                    $q2->where('user1', $userId1)->where('user2', $userId2);
                })->orWhere(function ($q2) use ($userId1, $userId2) {
                    $q2->where('user1', $userId2)->where('user2', $userId1);
                });
            })
            ->first();
    }

    /** IncomingMailService::mapReportReason(). */
    public static function mapReportReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return in_array($reason, self::REPORT_REASONS, true) ? $reason : 'Spam';
    }
}
