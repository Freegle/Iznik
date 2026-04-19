<?php

namespace App\Console\Commands\Queue;

use App\Console\Concerns\PreventsOverlapping;
use App\Mail\Charity\CharitySignupMail;
use App\Mail\Chat\ReferToSupportMail;
use App\Mail\Donation\DonateExternalMail;
use App\Mail\Newsfeed\ChitchatReportMail;
use App\Mail\Session\ForgotPasswordMail;
use App\Mail\Session\MergeOfferMail;
use App\Mail\Session\UnsubscribeConfirmMail;
use App\Mail\Session\VerifyEmailMail;
use App\Mail\Message\ModStdMessageMail;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Services\HousekeeperService;
use App\Services\PostcodeRemapService;
use App\Services\PushNotificationService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Processes background tasks queued by the Go API server.
 *
 * Go writes rows to the `background_tasks` table with a task_type and JSON data.
 * This command polls that table and dispatches each task to the appropriate handler.
 *
 * Runs as a daemon via supervisor or the Laravel scheduler.
 */
class ProcessBackgroundTasksCommand extends Command
{
    use GracefulShutdown;
    use PreventsOverlapping;

    protected $signature = 'queue:background-tasks
                            {--limit=50 : Maximum tasks to process per iteration}
                            {--max-iterations=60 : Maximum iterations before exiting (0 = infinite)}
                            {--sleep=5 : Seconds to sleep between iterations}
                            {--spool : Spool emails instead of sending directly}';

    protected $description = 'Process background tasks queued by the Go API server';

    private const MAX_ATTEMPTS = 3;

    public function handle(PushNotificationService $pushService, EmailSpoolerService $spooler): int
    {
        if (! $this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        try {
            return $this->doHandle($pushService, $spooler);
        } finally {
            $this->releaseLock();
        }
    }

    protected function doHandle(PushNotificationService $pushService, EmailSpoolerService $spooler): int
    {
        $limit = (int) $this->option('limit');
        $maxIterations = (int) $this->option('max-iterations');
        $sleepSeconds = (int) $this->option('sleep');
        $shouldSpool = (bool) $this->option('spool');
        $iteration = 0;

        $this->registerShutdownHandlers();
        $this->info("Processing background tasks (limit={$limit}, max-iterations={$maxIterations})");

        while (TRUE) {
            if ($this->shouldStop()) {
                $this->info('Shutdown signal received, stopping gracefully.');
                break;
            }

            $iteration++;
            if ($maxIterations > 0 && $iteration > $maxIterations) {
                $this->info("Reached max iterations ({$maxIterations}), exiting.");
                break;
            }

            $processed = $this->processIteration($limit, $pushService, $spooler, $shouldSpool);

            if ($processed === 0) {
                sleep($sleepSeconds);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Process one iteration of pending tasks.
     */
    protected function processIteration(
        int $limit,
        PushNotificationService $pushService,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): int {
        $tasks = DB::select(
            'SELECT * FROM background_tasks WHERE processed_at IS NULL AND failed_at IS NULL AND attempts < ? ORDER BY created_at ASC LIMIT ?',
            [self::MAX_ATTEMPTS, $limit]
        );

        $processed = 0;

        foreach ($tasks as $task) {
            if ($this->shouldStop()) {
                break;
            }

            try {
                DB::table('background_tasks')
                    ->where('id', $task->id)
                    ->increment('attempts');

                $data = json_decode($task->data, TRUE);

                $this->dispatchTask($task->task_type, $data, $pushService, $spooler, $shouldSpool);

                DB::table('background_tasks')
                    ->where('id', $task->id)
                    ->update(['processed_at' => now()]);

                $processed++;
            } catch (\Throwable $e) {
                Log::error('Background task failed', [
                    'task_id' => $task->id,
                    'task_type' => $task->task_type,
                    'error' => $e->getMessage(),
                    'attempts' => $task->attempts + 1,
                ]);

                // Report to Sentry so we get alerted to task failures.
                if (app()->bound('sentry')) {
                    app('sentry')->captureException($e);
                }

                $update = ['error_message' => substr($e->getMessage(), 0, 65535)];

                if ($task->attempts + 1 >= self::MAX_ATTEMPTS) {
                    $update['failed_at'] = now();
                }

                DB::table('background_tasks')
                    ->where('id', $task->id)
                    ->update($update);
            }
        }

        if ($processed > 0) {
            $this->info("Processed {$processed} task(s).");
        }

        return $processed;
    }

    /**
     * Dispatch a task to the appropriate handler.
     */
    protected function dispatchTask(
        string $taskType,
        array $data,
        PushNotificationService $pushService,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        match ($taskType) {
            'push_notify_group_mods' => $this->handlePushNotifyGroupMods($data, $pushService),
            'email_chitchat_report' => $this->handleEmailChitchatReport($data, $spooler, $shouldSpool),
            'email_charity_signup' => $this->handleEmailCharitySignup($data, $spooler, $shouldSpool),
            'email_donate_external' => $this->handleEmailDonateExternal($data, $spooler, $shouldSpool),
            'email_forgot_password' => $this->handleEmailForgotPassword($data, $spooler, $shouldSpool),
            'email_unsubscribe' => $this->handleEmailUnsubscribe($data, $spooler, $shouldSpool),
            'email_message_approved', 'email_message_rejected', 'email_message_reply'
                => $this->handleModStdMessage($taskType, $data, $pushService, $spooler, $shouldSpool),
            'email_mod_stdmsg'
                => $this->handleModStdMessageForMember($taskType, $data, $spooler, $shouldSpool),
            'email_merge' => $this->handleEmailMerge($data, $spooler, $shouldSpool),
            'email_verify' => $this->handleEmailVerify($data, $spooler, $shouldSpool),
            'refer_to_support' => $this->handleReferToSupport($data, $spooler, $shouldSpool),
            'message_outcome' => $this->handleMessageOutcome($data),
            'freebie_alerts_add' => $this->handleFreebieAlertsAdd($data),
            'freebie_alerts_remove' => $this->handleFreebieAlertsRemove($data),
            'housekeeper_notify' => $this->handleHousekeeperNotify($data),
            'remap_postcodes' => $this->handleRemapPostcodes($data),
            default => throw new \RuntimeException("Unknown task type: {$taskType}"),
        };
    }

    /**
     * Send push notifications to all moderators of a group.
     */
    protected function handlePushNotifyGroupMods(array $data, PushNotificationService $pushService): void
    {
        $groupId = $data['group_id'] ?? NULL;

        if (! $groupId) {
            throw new \RuntimeException('push_notify_group_mods requires group_id');
        }

        $count = $pushService->notifyGroupMods((int) $groupId);
        Log::info('Notified group mods', ['group_id' => $groupId, 'notified' => $count]);
    }

    /**
     * Send a ChitChat report email to support.
     */
    protected function handleEmailChitchatReport(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $required = ['user_id', 'user_name', 'user_email', 'newsfeed_id', 'reason'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("email_chitchat_report requires {$field}");
            }
        }

        $mail = new ChitchatReportMail(
            reporterName: $data['user_name'],
            reporterId: (int) $data['user_id'],
            reporterEmail: $data['user_email'],
            newsfeedId: (int) $data['newsfeed_id'],
            reason: $data['reason'],
        );

        if ($shouldSpool) {
            $recipients = array_map('trim', explode(',', config('freegle.mail.chitchat_support_addr')));
            $spooler->spool($mail, $recipients);
        } else {
            Mail::send($mail);
        }

        Log::info('Sent ChitChat report email', [
            'reporter_id' => $data['user_id'],
            'newsfeed_id' => $data['newsfeed_id'],
        ]);
    }

    /**
     * Send an external donation notification email to the info address.
     */
    protected function handleEmailDonateExternal(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $required = ['user_id', 'user_name', 'user_email', 'amount'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("email_donate_external requires {$field}");
            }
        }

        $mail = new DonateExternalMail(
            userName: $data['user_name'],
            userId: (int) $data['user_id'],
            userEmail: $data['user_email'],
            amount: (float) $data['amount'],
            source: $data['source'] ?? DonateExternalMail::SOURCE_EXTERNAL,
        );

        if ($shouldSpool) {
            $spooler->spool($mail, config('freegle.mail.info_addr'));
        } else {
            Mail::send($mail);
        }

        Log::info('Sent external donation email', [
            'user_id' => $data['user_id'],
            'amount' => $data['amount'],
        ]);
    }

    /**
     * Send a charity partner signup notification to the partnerships team.
     */
    protected function handleEmailCharitySignup(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        if (empty($data['orgname']) || empty($data['contactemail'])) {
            throw new \RuntimeException('email_charity_signup requires orgname and contactemail');
        }

        $mail = new CharitySignupMail(
            charityId: (int) $data['charity_id'],
            orgName: $data['orgname'],
            orgType: $data['orgtype'] ?? 'registered',
            charityNumber: $data['charitynumber'] ?? null,
            contactEmail: $data['contactemail'],
            contactName: $data['contactname'] ?? null,
            website: $data['website'] ?? null,
            description: $data['description'] ?? null,
        );

        if ($shouldSpool) {
            $spooler->spool($mail, config('freegle.mail.partnerships_addr'));
        } else {
            Mail::send($mail);
        }

        Log::info('Sent charity signup notification', [
            'charity_id' => $data['charity_id'],
            'orgname' => $data['orgname'],
        ]);
    }

    /**
     * Send a forgot-password email with auto-login link.
     */
    protected function handleEmailForgotPassword(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $required = ['user_id', 'email', 'reset_url'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("email_forgot_password requires {$field}");
            }
        }

        $mail = new ForgotPasswordMail(
            userId: (int) $data['user_id'],
            email: $data['email'],
            resetUrl: $data['reset_url'],
        );

        if ($shouldSpool) {
            $spooler->spool($mail, $data['email']);
        } else {
            Mail::send($mail);
        }

        Log::info('Sent forgot password email', [
            'user_id' => $data['user_id'],
        ]);
    }

    /**
     * Send an unsubscribe confirmation email with auto-login link.
     */
    protected function handleEmailUnsubscribe(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $required = ['user_id', 'email', 'unsub_url'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("email_unsubscribe requires {$field}");
            }
        }

        $mail = new UnsubscribeConfirmMail(
            userId: (int) $data['user_id'],
            email: $data['email'],
            unsubUrl: $data['unsub_url'],
        );

        if ($shouldSpool) {
            $spooler->spool($mail, $data['email']);
        } else {
            Mail::send($mail);
        }

        Log::info('Sent unsubscribe confirmation email', [
            'user_id' => $data['user_id'],
        ]);
    }

    /**
     * Handle mod standard message emails (approve, reject, reply).
     *
     * Looks up the message poster, group, and mod info, then:
     * 1. Sends the stdmsg email (if subject/body provided).
     * 2. Creates a User2Mod chat message for the mod log.
     * 3. Creates a mod log entry (always — even for plain approve with no stdmsg).
     * 4. Queues push notifications to group moderators.
     */
    protected function handleModStdMessage(
        string $taskType,
        array $data,
        PushNotificationService $pushService,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $msgId = (int) ($data['msgid'] ?? 0);
        $byUser = (int) ($data['byuser'] ?? 0);
        $groupId = (int) ($data['groupid'] ?? 0);
        $subject = $data['subject'] ?? '';
        $body = $data['body'] ?? '';
        $stdmsgId = (int) ($data['stdmsgid'] ?? 0);

        // Fall back to looking up group from messages_groups if not provided.
        if ($groupId === 0 && $msgId > 0) {
            $groupId = (int) (DB::table('messages_groups')->where('msgid', $msgId)->value('groupid') ?? 0);
        }

        if ($msgId === 0 || $byUser === 0) {
            throw new \RuntimeException("{$taskType} requires msgid and byuser");
        }

        // Look up the poster (needed for both log and email).
        $posterId = (int) DB::table('messages')->where('id', $msgId)->value('fromuser');

        // Determine the log subtype from the task type.
        // email_message_approved → Approved
        // email_message_rejected with subject → Rejected, without subject → Deleted
        // email_message_reply → Replied
        $subtype = match ($taskType) {
            'email_message_approved' => 'Approved',
            'email_message_rejected' => $subject !== '' ? 'Rejected' : 'Deleted',
            'email_message_reply' => 'Replied',
            default => 'Approved',
        };

        // Always create the mod log entry (even if no stdmsg content).
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'Message',
            'subtype' => $subtype,
            'msgid' => $msgId,
            'user' => $posterId ?: null,
            'byuser' => $byUser,
            'groupid' => $groupId ?: null,
            'stdmsgid' => $stdmsgId ?: null,
            'text' => $subject,
        ]);

        // Queue push notifications to group moderators.
        if ($groupId > 0) {
            $pushService->notifyGroupMods($groupId);
        }

        // No subject/body means no stdmsg email to send (e.g. plain approve without message).
        if ($subject === '' && $body === '') {
            Log::info("Mod action {$taskType} without stdmsg content, skipping email", [
                'msgid' => $msgId,
                'byuser' => $byUser,
            ]);
            return;
        }

        if (! $posterId) {
            Log::warning("No poster found for message {$msgId}");
            return;
        }

        $poster = User::find($posterId);
        $posterEmail = $poster?->email_preferred;

        if (! $posterEmail) {
            Log::warning("No email found for poster of message {$msgId}");
            return;
        }

        // Look up the group info.
        $groupName = '';
        $groupNameShort = '';
        $groupContactMail = null;
        if ($groupId > 0) {
            $group = DB::table('groups')->where('id', $groupId)->first();
            if ($group) {
                $groupName = $group->namefull ?: $group->nameshort ?? '';
                $groupNameShort = $group->nameshort ?? '';
                $groupContactMail = $group->contactmail ?: null;
            }
        }

        // Look up the mod's display name.
        $modName = DB::table('users')->where('id', $byUser)->value('fullname') ?? 'A volunteer';

        // Look up the message subject for context.
        $messageSubject = DB::table('messages')->where('id', $msgId)->value('subject') ?? '';

        $mail = new ModStdMessageMail(
            modName: $modName,
            groupName: $groupName,
            groupNameShort: $groupNameShort,
            stdSubject: $subject,
            stdBody: $body,
            messageSubject: $messageSubject,
            msgId: $msgId,
            recipientUserId: $posterId,
            recipientEmail: $posterEmail,
            groupContactMail: $groupContactMail,
        );

        if ($shouldSpool) {
            $spooler->spool($mail, $posterEmail);
        } else {
            Mail::to($posterEmail)->send($mail);
        }

        // V1 parity: send BCC copy if configured in mod's ModConfig.
        $this->sendBccIfConfigured(
            data: $data,
            byUser: $byUser,
            groupId: $groupId,
            groupNameShort: $groupNameShort,
            groupName: $groupName,
            subject: $subject,
            body: $body,
            recipientUserId: $posterId,
            recipientEmail: $posterEmail,
            messageSubject: $messageSubject,
            msgId: $msgId,
            groupContactMail: $groupContactMail,
            modName: $modName,
            spooler: $spooler,
            shouldSpool: $shouldSpool,
        );

        // Create a User2Mod chat message so the conversation appears in modtools chats.
        if ($groupId > 0) {
            $chatRoom = ChatRoom::getOrCreateUser2Mod($posterId, $groupId);

            if ($chatRoom) {
                DB::table('chat_messages')->insert([
                    'chatid' => $chatRoom->id,
                    'userid' => $byUser,
                    'message' => "{$subject}\r\n\r\n{$body}",
                    'type' => 'ModMail',
                    'refmsgid' => $msgId,
                    'date' => now(),
                    'reviewrequired' => 0,
                    'processingrequired' => 0,
                    'processingsuccessful' => 1,
                ]);
            }
        }

        Log::info("Sent mod stdmsg email ({$taskType})", [
            'msgid' => $msgId,
            'byuser' => $byUser,
            'groupid' => $groupId,
            'recipient' => $posterEmail,
        ]);
    }

    /**
     * Handle mod standard message emails sent to a member (not related to a message).
     *
     * V1 parity with User::mail() + User::maybeMail():
     * 1. Send email to the member.
     * 2. Create a User2Mod chat message for the mod log.
     */
    protected function handleModStdMessageForMember(
        string $taskType,
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $userId = (int) ($data['userid'] ?? 0);
        $byUser = (int) ($data['byuser'] ?? 0);
        $groupId = (int) ($data['groupid'] ?? 0);
        $subject = $data['subject'] ?? '';
        $body = $data['body'] ?? '';
        $stdmsgId = (int) ($data['stdmsgid'] ?? 0);

        if ($userId === 0 || $byUser === 0) {
            throw new \RuntimeException('email_mod_stdmsg requires userid and byuser');
        }

        if ($subject === '' && $body === '') {
            Log::info('Mod stdmsg for member without content, skipping email', [
                'userid' => $userId,
                'byuser' => $byUser,
            ]);
            return;
        }

        // Look up the member's preferred email.
        $member = User::find($userId);
        $memberEmail = $member?->email_preferred;

        if (! $memberEmail) {
            Log::warning("No email found for member {$userId}");
            return;
        }

        // Look up group info.
        $groupName = '';
        $groupNameShort = '';
        $groupContactMail = null;
        if ($groupId > 0) {
            $group = DB::table('groups')->where('id', $groupId)->first();
            if ($group) {
                $groupName = $group->namefull ?: $group->nameshort ?? '';
                $groupNameShort = $group->nameshort ?? '';
                $groupContactMail = $group->contactmail ?: null;
            }
        }

        // Look up the mod's display name.
        $modName = DB::table('users')->where('id', $byUser)->value('fullname') ?? 'A volunteer';

        $mail = new ModStdMessageMail(
            modName: $modName,
            groupName: $groupName,
            groupNameShort: $groupNameShort,
            stdSubject: $subject,
            stdBody: $body,
            messageSubject: '',
            msgId: 0,
            recipientUserId: $userId,
            recipientEmail: $memberEmail,
            groupContactMail: $groupContactMail,
        );

        if ($shouldSpool) {
            $spooler->spool($mail, $memberEmail);
        } else {
            Mail::to($memberEmail)->send($mail);
        }

        // V1 parity: send BCC copy if configured in mod's ModConfig.
        $this->sendBccIfConfigured(
            data: $data,
            byUser: $byUser,
            groupId: $groupId,
            groupNameShort: $groupNameShort,
            groupName: $groupName,
            subject: $subject,
            body: $body,
            recipientUserId: $userId,
            recipientEmail: $memberEmail,
            messageSubject: '',
            msgId: 0,
            groupContactMail: $groupContactMail,
            modName: $modName,
            spooler: $spooler,
            shouldSpool: $shouldSpool,
        );

        // Create a User2Mod chat message so the conversation appears in modtools chats.
        if ($groupId > 0) {
            $chatRoom = ChatRoom::getOrCreateUser2Mod($userId, $groupId);

            if ($chatRoom) {
                $chatMessageId = DB::table('chat_messages')->insertGetId([
                    'chatid' => $chatRoom->id,
                    'userid' => $byUser,
                    'message' => "{$subject}\r\n\r\n{$body}",
                    'type' => 'ModMail',
                    'date' => now(),
                    'reviewrequired' => 0,
                    'processingrequired' => 0,
                    'processingsuccessful' => 1,
                ]);

                // V1 parity: upToDate() — mark the chat message as already emailed to the member
                // so the notification daemon (NotifyUser2ModCommand) does not send a duplicate.
                // V1 calls $r->upToDate($fromuser) after the direct email send, which sets
                // lastmsgemailed = MAX(chat_messages.id) for the member's roster entry.
                DB::table('chat_roster')->upsert(
                    [
                        'chatid' => $chatRoom->id,
                        'userid' => $userId,
                        'lastmsgemailed' => $chatMessageId,
                        'lastemailed' => now(),
                    ],
                    ['chatid', 'userid'],
                    ['lastmsgemailed', 'lastemailed']
                );
            }

            // Only create the User/Mailed log for email_mod_stdmsg (direct mod message to member).
            // Membership approve/reject actions no longer route here — they use email_mod_stdmsg
            // directly (or create no task if no content), so we only log when it's a direct modmail.
            if ($taskType === 'email_mod_stdmsg') {
                DB::table('logs')->insert([
                    'timestamp' => now(),
                    'type' => 'User',
                    'subtype' => 'Mailed',
                    'byuser' => $byUser,
                    'user' => $userId,
                    'groupid' => $groupId,
                    'stdmsgid' => $stdmsgId ?: null,
                    'text' => $subject,
                ]);
                // Note: users_modmails is populated by the syncModMailCounts cron job
                // which scans the logs table — no direct insert needed here.
            }
        }

        Log::info('Sent mod stdmsg email to member', [
            'userid' => $userId,
            'byuser' => $byUser,
            'groupid' => $groupId,
            'recipient' => $memberEmail,
        ]);
    }

    /**
     * Handle message outcome background processing.
     *
     * V1 parity with Message::backgroundMark():
     * 1. Log the outcome to the logs table for each group the message is on.
     * 2. Notify interested users (who replied but didn't get the item) by creating
     *    TYPE_COMPLETED chat messages in their User2User chat rooms.
     */
    protected function handleMessageOutcome(array $data): void
    {
        $msgId = (int) ($data['msgid'] ?? 0);
        $byUser = (int) ($data['byuser'] ?? 0);
        $outcome = $data['outcome'] ?? '';
        $happiness = $data['happiness'] ?? '';
        $comment = $data['comment'] ?? '';
        $userid = (int) ($data['userid'] ?? 0);
        $messageForOthers = $data['message'] ?? '';

        if ($msgId === 0) {
            throw new \RuntimeException('message_outcome requires msgid');
        }

        // Get the message poster.
        $fromUser = (int) (DB::table('messages')->where('id', $msgId)->value('fromuser') ?? 0);

        // 1. Log the outcome for each group (V1: Log::TYPE_MESSAGE, Log::SUBTYPE_OUTCOME).
        $groups = DB::table('messages_groups')->where('msgid', $msgId)->pluck('groupid');

        foreach ($groups as $groupId) {
            DB::table('logs')->insert([
                'timestamp' => now(),
                'type' => 'Message',
                'subtype' => 'Outcome',
                'msgid' => $msgId,
                'user' => $fromUser ?: null,
                'byuser' => $byUser ?: null,
                'groupid' => $groupId,
                'text' => trim("{$outcome} {$comment}"),
            ]);
        }

        // 2. Notify interested users who replied but didn't get the item.
        // Find User2User chat rooms with INTERESTED messages referencing this message,
        // excluding users who are in messages_by (i.e. who got the item).
        $replies = DB::select(
            "SELECT DISTINCT chatid FROM chat_messages
             INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid AND chat_rooms.chattype = 'User2User'
             LEFT JOIN messages_by ON messages_by.msgid = chat_messages.refmsgid
                 AND messages_by.userid IN (chat_rooms.user1, chat_rooms.user2)
             WHERE refmsgid = ? AND chat_messages.type = 'Interested'
                 AND reviewrejected = 0 AND messages_by.id IS NULL",
            [$msgId]
        );

        foreach ($replies as $reply) {
            // Check if this message was unpromised in this chat (TYPE_RENEGED).
            // If so, don't send the generic message as it may not be appropriate.
            $unpromised = DB::table('chat_messages')
                ->where('chatid', $reply->chatid)
                ->where('refmsgid', $msgId)
                ->where('type', 'Reneged')
                ->exists();

            DB::table('chat_messages')->insert([
                'chatid' => $reply->chatid,
                'userid' => $fromUser,
                'message' => $unpromised ? null : ($messageForOthers ?: null),
                'type' => 'Completed',
                'refmsgid' => $msgId,
                'date' => now(),
                'reviewrequired' => 0,
                'processingrequired' => 0,
                'processingsuccessful' => 1,
            ]);

            // Mark the poster as up-to-date in this chat so it doesn't appear as unread to them.
            if ($fromUser) {
                DB::table('chat_roster')->updateOrInsert(
                    ['chatid' => $reply->chatid, 'userid' => $fromUser],
                    ['lastmsgseen' => DB::raw('(SELECT MAX(id) FROM chat_messages WHERE chatid = ' . (int) $reply->chatid . ')'), 'date' => now()]
                );
            }
        }

        Log::info('Processed message outcome', [
            'msgid' => $msgId,
            'outcome' => $outcome,
            'groups' => $groups->count(),
            'notified_chats' => count($replies),
        ]);
    }

    /**
     * Handle merge offer email — sends to both users involved in a merge.
     *
     * V1 parity: merge.php lines 149-211.
     */
    protected function handleEmailMerge(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $mergeId = (int) ($data['merge_id'] ?? 0);
        $uid = $data['uid'] ?? '';
        $user1Id = (int) ($data['user1'] ?? 0);
        $user2Id = (int) ($data['user2'] ?? 0);

        if ($mergeId === 0 || $uid === '' || $user1Id === 0 || $user2Id === 0) {
            throw new \RuntimeException('email_merge requires merge_id, uid, user1, user2');
        }

        $u1 = User::find($user1Id);
        $u2 = User::find($user2Id);

        if (! $u1 || ! $u2) {
            Log::warning('Merge user not found', ['user1' => $user1Id, 'user2' => $user2Id]);
            return;
        }

        $mergeUrl = config('freegle.sites.user') . '/merge?id=' . $mergeId . '&uid=' . $uid;
        $name1 = $u1->fullname ?: 'Freegle User';
        $name2 = $u2->fullname ?: 'Freegle User';
        $email1 = $this->obfuscateEmail($u1->email_preferred ?? '');
        $email2 = $this->obfuscateEmail($u2->email_preferred ?? '');

        // Send to both users.
        foreach ([$u1, $u2] as $recipient) {
            $recipientEmail = $recipient->email_preferred;

            if (! $recipientEmail) {
                continue;
            }

            $mail = new MergeOfferMail(
                recipientUserId: $recipient->id,
                recipientName: $recipient->fullname ?: 'Freegle User',
                recipientEmail: $recipientEmail,
                name1: $name1,
                email1: $email1,
                name2: $name2,
                email2: $email2,
                mergeUrl: $mergeUrl,
            );

            if ($shouldSpool) {
                $spooler->spool($mail, $recipientEmail);
            } else {
                Mail::to($recipientEmail)->send($mail);
            }
        }

        Log::info('Sent merge offer emails', [
            'merge_id' => $mergeId,
            'user1' => $user1Id,
            'user2' => $user2Id,
        ]);
    }

    /**
     * Handle email verification — generates a validate key and sends a confirmation link.
     *
     * V1 parity: User::verifyEmail() lines 3822-3896.
     */
    protected function handleEmailVerify(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $userId = (int) ($data['user_id'] ?? 0);
        $email = $data['email'] ?? '';

        if ($userId === 0 || $email === '') {
            throw new \RuntimeException('email_verify requires user_id and email');
        }

        $user = User::find($userId);

        if (! $user) {
            Log::warning("User not found for email verify: {$userId}");
            return;
        }

        // Check if this email is already one of the user's emails.
        $canon = strtolower(trim($email));
        $existing = DB::table('users_emails')
            ->where('userid', $userId)
            ->whereRaw('LOWER(email) = ?', [$canon])
            ->exists();

        if ($existing) {
            // Already the user's email — just make it primary.
            DB::table('users_emails')
                ->where('userid', $userId)
                ->whereRaw('LOWER(email) = ?', [$canon])
                ->update(['preferred' => 1]);

            DB::table('users_emails')
                ->where('userid', $userId)
                ->whereRaw('LOWER(email) != ?', [$canon])
                ->update(['preferred' => 0]);

            Log::info('Email already belongs to user, made primary', [
                'user_id' => $userId,
                'email' => $email,
            ]);
            return;
        }

        // Generate a validation key. Check if one was recently set (< 600s) to avoid confusion.
        $recentKey = DB::table('users_emails')
            ->where('canon', $canon)
            ->whereRaw('TIMESTAMPDIFF(SECOND, validatetime, NOW()) < 600')
            ->value('validatekey');

        $key = $recentKey;

        if (! $key) {
            $key = uniqid();
            DB::statement(
                'INSERT INTO users_emails (email, canon, validatekey, backwards) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE validatekey = ?',
                [$email, $canon, $key, strrev($canon), $key]
            );
        }

        // Generate the confirm URL with auto-login.
        $userKey = $user->getUserKey();
        $confirmPath = '/settings/confirmmail/' . urlencode($key);
        $userSite = config('freegle.sites.user');
        $confirmUrl = "{$userSite}{$confirmPath}?u={$userId}&k={$userKey}&src=changeemail";

        $mail = new VerifyEmailMail(
            userId: $userId,
            email: $email,
            confirmUrl: $confirmUrl,
        );

        if ($shouldSpool) {
            $spooler->spool($mail, $email);
        } else {
            Mail::to($email)->send($mail);
        }

        Log::info('Sent email verification', [
            'user_id' => $userId,
            'email' => $email,
        ]);
    }

    /**
     * Handle refer-to-support — sends a plain text email to the support team.
     *
     * V1 parity: ChatRoom::referToSupport() lines 2266-2284.
     */
    protected function handleReferToSupport(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $chatId = (int) ($data['chatid'] ?? 0);
        $userId = (int) ($data['userid'] ?? 0);

        if ($chatId === 0 || $userId === 0) {
            throw new \RuntimeException('refer_to_support requires chatid and userid');
        }

        $chat = DB::table('chat_rooms')->where('id', $chatId)->first();

        if (! $chat) {
            Log::warning("Chat not found for refer_to_support: {$chatId}");
            return;
        }

        $user = User::find($userId);

        if (! $user) {
            Log::warning("User not found for refer_to_support: {$userId}");
            return;
        }

        // The "other" user in the chat.
        $otherUserId = $chat->user1 == $userId ? $chat->user2 : $chat->user1;
        $otherUser = $otherUserId ? User::find($otherUserId) : null;
        $otherUserName = $otherUser ? ($otherUser->fullname ?: 'Unknown') : 'Unknown';

        // Get group mods email for reply-to.
        $groupId = $chat->groupid;
        $replyToAddress = config('freegle.mail.noreply_addr');
        $replyToName = config('freegle.branding.name');

        if ($groupId) {
            $group = DB::table('groups')->where('id', $groupId)->first();

            if ($group) {
                $groupNameShort = $group->nameshort ?? '';
                $replyToAddress = $groupNameShort . '-volunteers@' . config('freegle.mail.group_domain', 'groups.ilovefreegle.org');
                $replyToName = ($group->namefull ?: $groupNameShort) . ' Volunteers';
            }
        }

        $mail = new ReferToSupportMail(
            userName: $user->fullname ?: 'Unknown',
            userId: $userId,
            chatId: $chatId,
            otherUserName: $otherUserName,
            otherUserId: (int) ($otherUserId ?? 0),
            replyToAddress: $replyToAddress,
            replyToName: $replyToName,
        );

        $supportAddr = config('freegle.mail.support_addr', 'support@ilovefreegle.org');
        $recipients = array_map('trim', explode(',', $supportAddr));

        if ($shouldSpool) {
            $spooler->spool($mail, $recipients);
        } else {
            Mail::to($recipients)->send($mail);
        }

        Log::info('Sent refer to support email', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Process a housekeeping notification from the Chrome extension.
     */
    protected function handleHousekeeperNotify(array $data): void
    {
        $service = app(HousekeeperService::class);
        $service->process($data);
    }

    /**
     * Resolve the BCC address for a mod standard message action.
     *
     * V1 parity: ModConfig::getForGroup() + ModConfig::getBcc() + ModConfig::evalIt().
     *
     * @param int    $byUser  The moderator's user ID
     * @param int    $groupId The group ID
     * @param string $action  The action string (Approve, Reject, Leave Approved Member, etc.)
     * @return string|null    The resolved BCC email address, or null if none configured
     */
    private function resolveBccAddress(int $byUser, int $groupId, string $action): ?string
    {
        if ($groupId === 0 || $action === '') {
            return null;
        }

        // Step 1: Find the mod's config for this group (V1: ModConfig::getForGroup).
        $configId = DB::table('memberships')
            ->where('userid', $byUser)
            ->where('groupid', $groupId)
            ->value('configid');

        if (! $configId) {
            // Fall back to any other mod's config for this group.
            $configId = DB::table('memberships')
                ->where('groupid', $groupId)
                ->whereIn('role', ['Moderator', 'Owner'])
                ->whereNotNull('configid')
                ->value('configid');
        }

        if (! $configId) {
            // Fall back to any config created by this mod.
            $configId = DB::table('mod_configs')
                ->where('createdby', $byUser)
                ->value('id');
        }

        if (! $configId) {
            // Fall back to a default config.
            $configId = DB::table('mod_configs')
                ->where('default', 1)
                ->value('id');
        }

        if (! $configId) {
            return null;
        }

        // Step 2: Map action to CC column pair (V1: ModConfig::getBcc).
        [$toColumn, $addrColumn] = match ($action) {
            'Approve', 'Reject', 'Leave' => ['ccrejectto', 'ccrejectaddr'],
            'Leave Member' => ['ccrejmembto', 'ccrejmembaddr'],
            'Leave Approved Message', 'Delete Approved Message' => ['ccfollowupto', 'ccfollowupaddr'],
            'Leave Approved Member', 'Delete Approved Member' => ['ccfollmembto', 'ccfollmembaddr'],
            default => [null, null],
        };

        if (! $toColumn) {
            return null;
        }

        // Step 3: Look up the config and evaluate (V1: ModConfig::evalIt).
        $config = DB::table('mod_configs')
            ->where('id', $configId)
            ->first([$toColumn, $addrColumn]);

        if (! $config) {
            return null;
        }

        $to = $config->$toColumn;
        $addr = $config->$addrColumn;

        if ($to === 'Me') {
            $modUser = User::find($byUser);

            return $modUser?->email_preferred;
        }

        if ($to === 'Specific') {
            return $addr ?: null;
        }

        return null;
    }

    /**
     * Send a BCC copy of a mod standard message if configured.
     *
     * V1 parity: both Message::mail() and User::maybeMail() send a BCC copy
     * with body prefixed by "(This is a BCC of a message sent to Freegle user #...)".
     */
    private function sendBccIfConfigured(
        array $data,
        int $byUser,
        int $groupId,
        string $groupNameShort,
        string $groupName,
        string $subject,
        string $body,
        int $recipientUserId,
        string $recipientEmail,
        string $messageSubject,
        int $msgId,
        ?string $groupContactMail,
        string $modName,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $action = $data['action'] ?? '';
        if ($action === '' || $groupId === 0) {
            return;
        }

        $bccAddress = $this->resolveBccAddress($byUser, $groupId, $action);
        if (! $bccAddress) {
            return;
        }

        // V1: replace $groupname in BCC address.
        $bccAddress = str_replace('$groupname', $groupNameShort, $bccAddress);

        // V1: prefix BCC body with notice.
        $bccBody = "(This is a BCC of a message sent to Freegle user #{$recipientUserId} {$recipientEmail})\n\n{$body}";

        $bccMail = new ModStdMessageMail(
            modName: $modName,
            groupName: $groupName,
            groupNameShort: $groupNameShort,
            stdSubject: $subject,
            stdBody: $bccBody,
            messageSubject: $messageSubject,
            msgId: $msgId,
            recipientUserId: 0,
            recipientEmail: $bccAddress,
            groupContactMail: $groupContactMail,
        );

        if ($shouldSpool) {
            $spooler->spool($bccMail, $bccAddress);
        } else {
            Mail::to($bccAddress)->send($bccMail);
        }

        Log::info('Sent BCC copy of mod stdmsg', [
            'action' => $action,
            'bcc' => $bccAddress,
            'byuser' => $byUser,
            'groupid' => $groupId,
        ]);
    }

    /**
     * Add a post to freebiealerts.app.
     *
     * V1 parity with FreebieAlerts::add() — only sends outstanding Offers with
     * a location. TrashNothing messages are skipped (TN syncs directly).
     */
    protected function handleFreebieAlertsAdd(array $data): void
    {
        $msgId = (int) ($data['msgid'] ?? 0);
        if ($msgId === 0) {
            throw new \RuntimeException('freebie_alerts_add requires msgid');
        }

        $apiKey = config('freegle.freebie_alerts.api_key');
        if (empty($apiKey)) {
            Log::debug('Freebie Alerts API key not configured, skipping add', ['msgid' => $msgId]);
            return;
        }

        // Only outstanding Offers (no outcome yet).
        $msg = DB::table('messages')->where('id', $msgId)->first();
        if (! $msg || $msg->type !== 'Offer') {
            return;
        }

        $hasOutcome = DB::table('messages_outcomes')->where('msgid', $msgId)->exists();
        if ($hasOutcome) {
            return;
        }

        // Skip TrashNothing messages — TN syncs to freebiealerts directly.
        if ($msg->sourceheader && str_starts_with($msg->sourceheader, 'TN-')) {
            return;
        }

        $group = DB::table('messages_groups')
            ->where('msgid', $msgId)
            ->where('collection', 'Approved')
            ->first();
        if (! $group) {
            return;
        }

        if (! $msg->lat || ! $msg->lng) {
            return;
        }

        // Build image list from message attachments.
        // Same pattern as digest emails: externalurl if set, otherwise {images.domain}/timg_{id}.jpg.
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $attachments = DB::table('messages_attachments')->where('msgid', $msgId)->get();
        $images = $attachments->map(function ($att) use ($imagesDomain) {
            return ! empty($att->externalurl)
                ? $att->externalurl
                : "{$imagesDomain}/timg_{$att->id}.jpg";
        })->implode(',');

        $body = $msg->textbody ?: 'No description';

        $payload = [
            'id' => $msgId,
            'title' => $msg->subject,
            'description' => $body,
            'latitude' => $msg->lat,
            'longitude' => $msg->lng,
            'images' => $images,
            'created_at' => $group->arrival,
        ];

        $apiUrl = config('freegle.freebie_alerts.api_url');

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Content-type' => 'application/json',
                'Key' => $apiKey,
            ])->timeout(60)->post("{$apiUrl}/freegle/post/create", $payload);

            if ($response->successful()) {
                Log::info('Added post to Freebie Alerts', ['msgid' => $msgId]);
            } else {
                Log::warning('Freebie Alerts add failed', [
                    'msgid' => $msgId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                if (app()->bound('sentry')) {
                    app('sentry')->captureMessage("Freebie Alerts add failed for message {$msgId}: {$response->status()}");
                }
            }
        } catch (\Throwable $e) {
            Log::error('Freebie Alerts add exception', [
                'msgid' => $msgId,
                'error' => $e->getMessage(),
            ]);
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        }
    }

    /**
     * Remove a post from freebiealerts.app.
     *
     * V1 parity with FreebieAlerts::remove().
     */
    protected function handleFreebieAlertsRemove(array $data): void
    {
        $msgId = (int) ($data['msgid'] ?? 0);
        if ($msgId === 0) {
            throw new \RuntimeException('freebie_alerts_remove requires msgid');
        }

        $apiKey = config('freegle.freebie_alerts.api_key');
        if (empty($apiKey)) {
            Log::debug('Freebie Alerts API key not configured, skipping remove', ['msgid' => $msgId]);
            return;
        }

        $apiUrl = config('freegle.freebie_alerts.api_url');

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Content-type' => 'application/json',
                'Key' => $apiKey,
            ])->timeout(60)->post("{$apiUrl}/freegle/post/{$msgId}/delete");

            if ($response->successful()) {
                Log::info('Removed post from Freebie Alerts', ['msgid' => $msgId]);
            } else {
                Log::warning('Freebie Alerts remove failed', [
                    'msgid' => $msgId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Freebie Alerts remove exception', [
                'msgid' => $msgId,
                'error' => $e->getMessage(),
            ]);
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        }
    }

    /**
     * Obfuscate an email address for display (e.g. "j***@example.com").
     */
    private function obfuscateEmail(string $email): string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        if (strlen($local) <= 1) {
            return '*@' . $domain;
        }

        return $local[0] . str_repeat('*', strlen($local) - 1) . '@' . $domain;
    }

    /**
     * Remap postcodes to their nearest area after a location geometry change.
     */
    protected function handleRemapPostcodes(array $data): void
    {
        $locationId = isset($data['location_id']) ? (int) $data['location_id'] : NULL;
        $polygon = $data['polygon'] ?? NULL;

        $service = app(PostcodeRemapService::class);
        $updated = $service->remapPostcodes($locationId, $polygon);

        Log::info('Remapped postcodes', [
            'location_id' => $locationId,
            'updated' => $updated,
        ]);
    }
}
