<?php

namespace App\Console\Commands\Queue;

use App\Console\Commands\Mail\SendAdminCommand;
use App\Console\Concerns\PreventsOverlapping;
use App\Mail\Admin\AdminMail;
use App\Mail\Charity\CharitySignupMail;
use App\Mail\Chat\ChatSpamReportMail;
use App\Mail\Chat\ReferToSupportMail;
use App\Mail\Donation\DonateExternalMail;
use App\Mail\Newsfeed\ChitchatReportMail;
use App\Mail\Session\ForgotPasswordMail;
use App\Mail\Session\LoginLinkMail;
use App\Mail\Session\MergeOfferMail;
use App\Mail\Session\UnsubscribeConfirmMail;
use App\Mail\Session\VerifyEmailMail;
use App\Mail\Message\ModStdMessageMail;
use App\Models\BackgroundTask;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use App\Services\BlockedKeywordBackfillService;
use App\Services\EmailSpoolerService;
use App\Services\HousekeeperService;
use App\Services\Lockdown\LockdownService;
use App\Services\PostcodeRemapService;
use App\Services\UserManagementService;
use App\Services\Ux\UxExperimentRefused;
use App\Services\Ux\UxExperimentService;
use App\Services\PushNotificationService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Processes background tasks queued by the Go API server.
 *
 * Go writes rows to the `background_tasks` table with a task_type and JSON data.
 * This command polls that table and dispatches each task to the appropriate handler.
 *
 * Runs as a daemon via supervisor or the Laravel scheduler.
 */
#[AsCommand(name: 'queue:background-tasks')]
class ProcessBackgroundTasksCommand extends Command
{
    use GracefulShutdown;
    use PreventsOverlapping;

    /**
     * Told to a member who replied to an Offer that has since been promised to someone else.
     * Also the key that stops them being told twice about the same post.
     */
    public const PROMISED_TO_SOMEONE_ELSE_NOTICE = 'This has been promised to someone else for now. If it falls through, the giver may come back to you.';

    protected $signature = 'queue:background-tasks
                            {--limit=50 : Maximum tasks to process per iteration}
                            {--max-iterations=60 : Maximum iterations before exiting (0 = infinite)}
                            {--sleep=5 : Seconds to sleep between iterations}
                            {--spool : Spool emails instead of sending directly}';

    protected $description = 'Process background tasks queued by the Go API server';

    private const MAX_ATTEMPTS = 3;

    /**
     * Email task types still processed while a lockdown holds `email` (plan
     * 2026-09-27-lockdown-switch.md, section 11.4) - matches
     * MailSuppressionService::ALLOWLISTED_EMAIL_TYPES_WHILE_HELD. Kept as task
     * types rather than re-deriving from data, since the choice between
     * ForgotPasswordMail and LoginLinkMail for email_forgot_password is made
     * inside its handler, after this gate, and both are allowlisted anyway.
     */
    private const EMAIL_TASKS_ALLOWED_WHILE_HELD = [
        BackgroundTask::TASK_EMAIL_FORGOT_PASSWORD,
        BackgroundTask::TASK_EMAIL_VERIFY,
        BackgroundTask::TASK_EMAIL_UNSUBSCRIBE,
    ];

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
        $this->registerFatalErrorHandler();
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

            // Idle: sleep before polling again, unless this was the last iteration,
            // in which case there is nothing to wait for. A --max-iterations=1 run
            // used to idle for the full --sleep before exiting.
            $lastIteration = $maxIterations > 0 && $iteration >= $maxIterations;
            if ($processed === 0 && ! $lastIteration) {
                sleep($sleepSeconds);
            }
        }

        return Command::SUCCESS;
    }

    // Fatal errors bypass try/catch; this shutdown function is the only hook that runs after them.
    protected function registerFatalErrorHandler(): void
    {
        if ($this->isTestingEnvironment()) {
            return;
        }

        // 2 MB reserve freed at shutdown entry — ensures we have heap for Log/Sentry after OOM.
        $memoryReserve = str_repeat("\0", 2 * 1024 * 1024);

        register_shutdown_function(function () use (&$memoryReserve) {
            unset($memoryReserve);
            $this->handleFatalShutdown(error_get_last());
        });
    }

    public function handleFatalShutdown(?array $error): void
    {
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

        if ($error === null || ! in_array($error['type'], $fatalTypes, TRUE)) {
            return;
        }

        $message = sprintf(
            'Fatal error in queue:background-tasks: %s in %s:%d',
            $error['message'],
            $error['file'],
            $error['line']
        );

        Log::critical($message, ['error' => $error]);

        if (app()->bound('sentry')) {
            app('sentry')->captureMessage($message, \Sentry\Severity::fatal());
        }
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
        $tasks = DB::table('background_tasks')
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            // A retry is queued with created_at in the future, for when it falls due. Compared on
            // the database clock, which also stamps rows queued by the Go API, so a database
            // timezone that differs from the app's cannot hold ordinary tasks back.
            // keep-raw: NOW() must be the database's clock, and the builder binds a PHP time instead.
            ->whereRaw('created_at <= NOW()')
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->all();

        $processed = 0;

        foreach ($tasks as $task) {
            if ($this->shouldStop()) {
                break;
            }

            // Every task passes through here (section 11.6 point 3), so this is the one
            // place to mark the background-tasks loop as still running.
            app(LockdownService::class)->ack('background-tasks');

            // Step over email tasks outside the lockdown allowlist while email is held -
            // left exactly as they are (no attempt spent, no processed_at) for a later
            // iteration once email is lifted. Re-checked every task rather than once per
            // iteration: held() is a memory read within LockdownService's five-second
            // cache, so this costs nothing extra beyond the first check every five seconds.
            if (str_starts_with($task->task_type, 'email_')
                && !in_array($task->task_type, self::EMAIL_TASKS_ALLOWED_WHILE_HELD, true)
                && app(LockdownService::class)->held('email')) {
                continue;
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
            BackgroundTask::TASK_PUSH_NOTIFY_CHAT_MESSAGE => $this->handlePushNotifyChatMessage($data, $pushService),
            BackgroundTask::TASK_PUSH_NOTIFY_GROUP_MODS  => $this->handlePushNotifyGroupMods($data, $pushService),
            BackgroundTask::TASK_EMAIL_CHITCHAT_REPORT   => $this->handleEmailChitchatReport($data, $spooler, $shouldSpool),
            BackgroundTask::TASK_EMAIL_CHAT_SPAM_REPORT  => $this->handleEmailChatSpamReport($data, $spooler, $shouldSpool),
            BackgroundTask::TASK_EMAIL_ADMIN_TEST        => $this->handleEmailAdminTest($data, $spooler),
            BackgroundTask::TASK_EMAIL_CHARITY_SIGNUP    => $this->handleEmailCharitySignup($data, $spooler, $shouldSpool),
            BackgroundTask::TASK_EMAIL_DONATE_EXTERNAL   => $this->handleEmailDonateExternal($data, $spooler, $shouldSpool),
            BackgroundTask::TASK_EMAIL_FORGOT_PASSWORD   => $this->handleEmailForgotPassword($data, $spooler, $shouldSpool),
            BackgroundTask::TASK_EMAIL_UNSUBSCRIBE       => $this->handleEmailUnsubscribe($data, $spooler, $shouldSpool),
            BackgroundTask::TASK_EMAIL_MESSAGE_APPROVED,
            BackgroundTask::TASK_EMAIL_MESSAGE_REJECTED,
            BackgroundTask::TASK_EMAIL_MESSAGE_REPLY     => $this->handleModStdMessage($taskType, $data, $pushService, $spooler, $shouldSpool),
            BackgroundTask::TASK_EMAIL_MOD_STDMSG        => $this->handleModStdMessageForMember($taskType, $data, $spooler, $shouldSpool),
            BackgroundTask::TASK_EMAIL_MERGE             => $this->handleEmailMerge($data, $spooler, $shouldSpool),
            BackgroundTask::TASK_EMAIL_VERIFY            => $this->handleEmailVerify($data, $spooler, $shouldSpool),
            BackgroundTask::TASK_REFER_TO_SUPPORT        => $this->handleReferToSupport($data, $spooler, $shouldSpool),
            BackgroundTask::TASK_MESSAGE_OUTCOME         => $this->handleMessageOutcome($data),
            BackgroundTask::TASK_MESSAGE_PROMISED        => $this->handleMessagePromised($data),
            BackgroundTask::TASK_FREEBIE_ALERTS_ADD      => $this->handleFreebieAlertsAdd($data),
            BackgroundTask::TASK_FREEBIE_ALERTS_REMOVE   => $this->handleFreebieAlertsRemove($data),
            BackgroundTask::TASK_HOUSEKEEPER_NOTIFY      => $this->handleHousekeeperNotify($data),
            BackgroundTask::TASK_CONCERN_KEYWORD_BACKFILL => $this->handleConcernKeywordBackfill($data),
            BackgroundTask::TASK_REMAP_POSTCODES         => $this->handleRemapPostcodes($data),
            BackgroundTask::TASK_USER_FORGET             => $this->handleUserForget($data),
            BackgroundTask::TASK_TN_SYNC                 => $this->handleTnSyncCommand($data),
            BackgroundTask::TASK_UX_EXPERIMENT           => $this->handleUxExperiment($data),
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
     * Send chat-message push notifications to chat participants (and group
     * mods for User2Mod chats). Enqueued by ChatProcessService after a
     * message passes spam/review/ban checks — V1 parity for the push side
     * of ChatMessage::process() lost in commit 5cbb607b7.
     */
    protected function handlePushNotifyChatMessage(array $data, PushNotificationService $pushService): void
    {
        $messageId = (int) ($data['message_id'] ?? 0);

        if (! $messageId) {
            throw new \RuntimeException('push_notify_chat_message requires message_id');
        }

        $count = $pushService->notifyChatMessage($messageId);
        Log::info('Notified chat message', ['message_id' => $messageId, 'notified' => $count]);
    }

    /**
     * Send a ChitChat report email to support.
     */
    protected function handleEmailChitchatReport(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $required = ['user_id', 'newsfeed_id', 'reason'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("email_chitchat_report requires {$field}");
            }
        }

        // user_name and user_email are cosmetic — reporters are identified by user_id.
        $reporterName = !empty($data['user_name']) ? $data['user_name'] : 'A Freegle user';

        $mail = new ChitchatReportMail(
            reporterName: $reporterName,
            reporterId: (int) $data['user_id'],
            reporterEmail: $data['user_email'] ?? '',
            newsfeedId: (int) $data['newsfeed_id'],
            reason: $data['reason'],
        );

        $recipients = array_map('trim', explode(',', config('freegle.mail.chitchat_support_addr')));
        $spooler->spool($mail, $recipients);

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

        $spooler->spool($mail, config('freegle.mail.info_addr'));

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

        $spooler->spool($mail, config('freegle.mail.partnerships_addr'));

        Log::info('Sent charity signup notification', [
            'charity_id' => $data['charity_id'],
            'orgname' => $data['orgname'],
        ]);
    }

    /**
     * Send a test copy of an ADMIN to the one address the moderator gave, built exactly as a member
     * of the group would get it, with the moderator standing in for the member.
     */
    protected function handleEmailAdminTest(array $data, EmailSpoolerService $spooler): void
    {
        foreach (['user_id', 'email', 'subject', 'text'] as $field) {
            if (empty($data[$field])) {
                throw new \RuntimeException("email_admin_test requires {$field}");
            }
        }

        $user = User::find((int) $data['user_id']);
        if (!$user) {
            throw new \RuntimeException("email_admin_test: user {$data['user_id']} not found");
        }

        $groupName = $modsEmail = $groupShort = null;
        $volunteers = [];
        $group = !empty($data['groupid']) ? Group::find((int) $data['groupid']) : null;
        if ($group) {
            $groupName = $group->namefull ?: $group->nameshort;
            $modsEmail = $group->nameshort ? "{$group->nameshort}-volunteers@groups.ilovefreegle.org" : null;
            $groupShort = $group->nameshort;
            $volunteers = SendAdminCommand::getLocalVolunteers($group->id);
        }

        $admin = SendAdminCommand::personalise([
            'groupid' => $group?->id,
            'subject' => $data['subject'],
            'text' => $data['text'],
            'mjml' => $data['mjml'] ?? null,
            'ctatext' => $data['ctatext'] ?? null,
            'ctalink' => $data['ctalink'] ?? null,
            'essential' => $data['essential'] ?? true,
            'template' => ($data['template'] ?? '') ?: null,
        ], $groupName, $modsEmail, $user);

        $mail = new AdminMail($user, $admin, $groupName, $modsEmail, $groupShort, $volunteers, $data['email']);
        $spooler->spool($mail, $data['email']);

        Log::info('Sent ADMIN test', ['user_id' => $user->id, 'groupid' => $group?->id]);
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

        if (config('freegle.auth.passwordless', false)) {
            // Passwordless deployments: the same request means "send me a
            // sign-in link". The Go API has already minted the member's u/k
            // credentials into reset_url; keep those, but land on the page that
            // signs them in rather than the set-a-new-password settings page.
            $mail = new LoginLinkMail(
                userId: (int) $data['user_id'],
                email: $data['email'],
                loginUrl: $this->signInLinkFromResetUrl($data['reset_url']),
            );

            $spooler->spool($mail, $data['email'], 'signin_link');

            Log::info('Sent sign-in link email', [
                'user_id' => $data['user_id'],
            ]);

            return;
        }

        $mail = new ForgotPasswordMail(
            userId: (int) $data['user_id'],
            email: $data['email'],
            resetUrl: $data['reset_url'],
        );

        $spooler->spool($mail, $data['email'], 'password_reset');

        Log::info('Sent forgot password email', [
            'user_id' => $data['user_id'],
        ]);
    }

    /**
     * Turn the Go-generated reset URL (.../settings?u=&k=&src=forgotpass) into a
     * sign-in link: the same u/k credentials on freegle.auth.login_link_path,
     * anchored to OUR configured public site rather than whatever host the API
     * baked in (that env can lag - e.g. a localhost default - and would send
     * members an unusable link).
     */
    protected function signInLinkFromResetUrl(string $resetUrl): string
    {
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);

        $base = rtrim((string) config('freegle.sites.user'), '/');
        $path = '/'.ltrim((string) config('freegle.auth.login_link_path', '/'), '/');

        return sprintf(
            '%s%s?u=%s&k=%s',
            $base,
            $path,
            rawurlencode((string) ($query['u'] ?? '')),
            rawurlencode((string) ($query['k'] ?? '')),
        );
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

        $spooler->spool($mail, $data['email'], 'unsubscribe_confirm');

        Log::info('Sent unsubscribe confirmation email', [
            'user_id' => $data['user_id'],
        ]);
    }

    /**
     * Reopen any 'Closed' chat rosters for a chat so it reappears in the
     * recipient's chat list after a new message. The ModTools chat list filters
     * out rooms whose roster status is 'Closed' (iznik-server-go
     * chat/chatroom.go), so a mod who had previously closed a User2Mod chat would
     * not see it again even after sending a modmail to it (Discourse #9481/541).
     * Mirrors the reopen in V2 CreateChatMessage; 'Blocked' rosters are left as-is.
     */
    protected function reopenClosedRosters(int $chatId): void
    {
        DB::table('chat_roster')
            ->where('chatid', $chatId)
            ->where('status', 'Closed')
            ->update(['status' => 'Offline']);
    }

    /**
     * Whether a post is a TN post that was never addressed to the Freegle community it
     * landed on, and so has a poster no moderator here may write to.
     *
     * The origin row (rippled_in = 0) is the one that carries the answer: the rippling
     * engine inserts its copies without the column, so they take the table default
     * (allowed) and would mask it. Mirrors modmessaging.PostIsUnaddressed in
     * iznik-server-go, which is where the interactive paths enforce the same rule.
     */
    protected function postIsUnaddressed(int $msgId): bool
    {
        if ($msgId <= 0) {
            return false;
        }

        return DB::table('messages_groups')
            ->where('msgid', $msgId)
            ->where('rippled_in', 0)
            ->where('mod_messaging_allowed', 0)
            ->exists();
    }

    /**
     * Whether every post this person has made is a TN post matched to a Freegle community
     * they never chose - so they have not opted in to Freegle and cannot be written to.
     *
     * A "mixed" poster (one such post AND an ordinary one) is a real member and is not
     * restricted. Mirrors modmessaging.UserIsUnaddressedOnly in iznik-server-go.
     */
    protected function userIsUnaddressedOnly(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        // Two questions, not one aggregate: has this person any post of that kind, and
        // have they any post that is NOT of that kind. Someone who has never posted at all
        // answers no to the first and is unrestricted, which is what we want.
        $originRows = fn () => DB::table('messages as m')
            ->join('messages_groups as mg', function ($join) {
                $join->on('mg.msgid', '=', 'm.id')->where('mg.rippled_in', 0);
            })
            ->where('m.fromuser', $userId);

        if (!$originRows()->where('mg.mod_messaging_allowed', 0)->exists()) {
            return false;
        }

        return !$originRows()->where('mg.mod_messaging_allowed', 1)->exists();
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
            BackgroundTask::TASK_EMAIL_MESSAGE_APPROVED => 'Approved',
            BackgroundTask::TASK_EMAIL_MESSAGE_REJECTED => $subject !== '' ? 'Rejected' : 'Deleted',
            BackgroundTask::TASK_EMAIL_MESSAGE_REPLY    => 'Replied',
            default => 'Approved',
        };

        // Create the mod log entry (even if no stdmsg content).
        //
        // The Go reply handler (handleReply) now writes the "Replied" log synchronously,
        // exactly once. This INSERT is unconditional and re-runs whenever the task is
        // retried (e.g. after a transient email-spool failure), so leaving it in place for
        // replies produced duplicate "Replied" rows in the mod history (Discourse 9672/6).
        // Skip it for replies; approve/reject/delete still log here as before.
        if ($taskType !== BackgroundTask::TASK_EMAIL_MESSAGE_REPLY) {
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
        }

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

        // A TN post placed on a Freegle community its poster never chose (the origin
        // messages_groups row's mod_messaging_allowed = 0) carries no agreement to hear
        // from that community's volunteers. ModTools offers no standard messages for these
        // and the Go API refuses them, so a task reaching here with content came from a
        // stale client - the log and the mods' push above still stand, but nothing is sent
        // to the poster and no modmail thread is opened with them.
        if ($this->postIsUnaddressed($msgId)) {
            Log::info("Mod action {$taskType} on an unaddressed TN post, sending nothing to the poster", [
                'msgid' => $msgId,
                'byuser' => $byUser,
            ]);
            return;
        }

        // The acting group is a group the post RIPPLED INTO. Its moderators administer
        // their own copy; correspondence about the post belongs to the community it was
        // posted on (Discourse 10102). The log entry and moderator push above still
        // happen, because the action did happen here - it is only the words to the
        // freegler that are dropped. Absent means notify: every task queued before this
        // existed, and every home-group action, must still reach the poster.
        if (array_key_exists('notifyposter', $data) && (int) $data['notifyposter'] === 0) {
            Log::info("Mod action {$taskType} on a rippled-in copy, poster not contacted", [
                'msgid' => $msgId,
                'groupid' => $groupId,
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

        $spooler->spool($mail, $posterEmail);

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

                // Reopen any closed rosters so the chat reappears in the acting
                // mod's (and the member's) chat list, mirroring V2 CreateChatMessage
                // (iznik-server-go chat/chatmessage.go). Without this, a chat the mod
                // had previously closed stays hidden from their ModTools chats list
                // even after they send this modmail (Discourse #9481/541).
                $this->reopenClosedRosters((int) $chatRoom->id);
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

        // Someone whose only presence on Freegle is TN posts matched to communities they
        // never chose has not opted in, and their volunteers have no standing to write to
        // them. ModTools offers nothing that would and the Go API refuses it, so a task
        // reaching here came from a stale client.
        if ($this->userIsUnaddressedOnly($userId)) {
            Log::info('Mod stdmsg for a member who has not opted in to Freegle, sending nothing', [
                'userid' => $userId,
                'byuser' => $byUser,
            ]);
            return;
        }

        // The member's only tie to this group is a post of theirs that rippled in. The
        // group's decision about them still stands and is logged by the API; what does not
        // go is the message, because they never joined this community (Discourse 10102).
        // Absent means notify, so every task queued before this existed still sends.
        if (array_key_exists('notifyposter', $data) && (int) $data['notifyposter'] === 0) {
            Log::info('Mod stdmsg for a member whose membership rippling created, not contacted', [
                'userid' => $userId,
                'groupid' => $groupId,
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

        $spooler->spool($mail, $memberEmail);

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

                // Reopen any closed rosters so the chat reappears in the acting
                // mod's (and the member's) chat list — see reopenClosedRosters().
                $this->reopenClosedRosters((int) $chatRoom->id);
            }

            // Only create the User/Mailed log for email_mod_stdmsg (direct mod message to member).
            // Membership approve/reject actions no longer route here — they use email_mod_stdmsg
            // directly (or create no task if no content), so we only log when it's a direct modmail.
            if ($taskType === BackgroundTask::TASK_EMAIL_MOD_STDMSG) {
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

        // The post has gone, so a reply still waiting for its sender's answer (the question Freegle asks about a
        // damaged or bulky offer) is never delivered. Done first: the chats below are chosen by what was delivered.
        $this->expireReplyQuestions($msgId);

        // 2. Notify interested users who replied but didn't get the item.
        // Find User2User chat rooms with INTERESTED messages referencing this message,
        // excluding users who are in messages_by (i.e. who got the item).
        $replies = $this->interestedChatsQuery($msgId)
            ->leftJoin('messages_by', function ($join) {
                $join->on('messages_by.msgid', '=', 'chat_messages.refmsgid')
                    ->on(function ($j) {
                        $j->on('messages_by.userid', '=', 'chat_rooms.user1')
                            ->orOn('messages_by.userid', '=', 'chat_rooms.user2');
                    });
            })
            ->whereNull('messages_by.id')
            ->get();

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
                $lastMsgId = DB::table('chat_messages')->where('chatid', $reply->chatid)->max('id');
                DB::table('chat_roster')->updateOrInsert(
                    ['chatid' => $reply->chatid, 'userid' => $fromUser],
                    ['lastmsgseen' => $lastMsgId, 'date' => now()]
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
     * The User2User chats of members who replied to a post: those with an Interested message about it. A reply
     * still held for its sender's answer is left out, because the poster never had it, and a message from the
     * poster would land in a chat they have nothing in.
     */
    protected function interestedChatsQuery(int $msgId): \Illuminate\Database\Query\Builder
    {
        return DB::table('chat_messages')
            ->join('chat_rooms', function ($join) {
                $join->on('chat_rooms.id', '=', 'chat_messages.chatid')
                    ->where('chat_rooms.chattype', 'User2User');
            })
            ->where('refmsgid', $msgId)
            ->where('chat_messages.type', 'Interested')
            ->where('reviewrejected', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('chat_prompts as cpr')
                    ->whereColumn('cpr.heldchatmsgid', 'chat_messages.id')
                    ->where('cpr.kind', 'confirm_reply')
                    ->where('chat_messages.processingsuccessful', 0);
            })
            ->distinct()
            ->select('chatid', 'chat_rooms.user1', 'chat_rooms.user2');
    }

    /**
     * Handle a promise of an Offer: tell the other members who replied that it has been promised to someone else.
     *
     * Mirrors the Taken notice in handleMessageOutcome: one message in each replier's chat with the giver, written
     * by the giver and inserted already processed. A member is told once per post, however often it is promised
     * again. Nothing is sent when the promise no longer exists, when items are still unpromised, or when the post
     * has gone.
     */
    protected function handleMessagePromised(array $data): void
    {
        $msgId = (int) ($data['msgid'] ?? 0);
        $promisee = (int) ($data['userid'] ?? 0);

        if ($msgId === 0) {
            throw new \RuntimeException('message_promised requires msgid');
        }

        $message = DB::table('messages')->where('id', $msgId)->first(['fromuser', 'type', 'availablenow']);
        if (! $message || $message->type !== Message::TYPE_OFFER || DB::table('messages_outcomes')->where('msgid', $msgId)->exists()) {
            return;
        }

        // The same test as message.PromisedFor in the Go API: one promise is one item.
        $promises = DB::table('messages_promises')->where('msgid', $msgId)->count();
        if ($promises === 0 || $promises < max((int) $message->availablenow, 1)) {
            return;
        }

        $fromUser = (int) $message->fromuser;
        $told = 0;

        foreach ($this->interestedChatsQuery($msgId)->get() as $chat) {
            $replier = (int) $chat->user1 === $fromUser ? (int) $chat->user2 : (int) $chat->user1;

            if ($replier === $promisee) {
                continue;
            }

            $alreadyTold = DB::table('chat_messages')
                ->where('chatid', $chat->chatid)
                ->where('refmsgid', $msgId)
                ->where('type', ChatMessage::TYPE_SYSTEM)
                ->where('message', self::PROMISED_TO_SOMEONE_ELSE_NOTICE)
                ->exists();
            if ($alreadyTold) {
                continue;
            }

            DB::table('chat_messages')->insert([
                'chatid' => $chat->chatid,
                'userid' => $fromUser,
                'message' => self::PROMISED_TO_SOMEONE_ELSE_NOTICE,
                'type' => ChatMessage::TYPE_SYSTEM,
                'refmsgid' => $msgId,
                'date' => now(),
                'reviewrequired' => 0,
                'processingrequired' => 0,
                'processingsuccessful' => 1,
            ]);

            // Mark the giver as up-to-date in this chat so it doesn't appear as unread to them.
            $lastMsgId = DB::table('chat_messages')->where('chatid', $chat->chatid)->max('id');
            DB::table('chat_roster')->updateOrInsert(
                ['chatid' => $chat->chatid, 'userid' => $fromUser],
                ['lastmsgseen' => $lastMsgId, 'date' => now()]
            );

            $told++;
        }

        Log::info('Processed message promise', ['msgid' => $msgId, 'notified_chats' => $told]);
    }

    /**
     * Close the reply questions (chat_prompts kind confirm_reply) about a post that has gone, and drop the replies
     * waiting on them. A question still open is answered "expired" so that its buttons stop, and the reply it held is
     * withdrawn, with whatever the same member wrote after it in that chat while the question was open: the item is
     * gone, so there is nothing left for the poster to be told. A reply already answered yes but not yet delivered is
     * dropped the same way, so the batch cannot deliver it after the item has gone. A question answered no, or over,
     * refused its messages at the time, and anything the member wrote in that chat since is an ordinary message that
     * is left alone; the same goes for what they wrote after a yes was delivered.
     */
    protected function expireReplyQuestions(int $msgId): void
    {
        $questions = DB::table('chat_prompts as cp')
            ->join('chat_messages as held', 'held.id', '=', 'cp.heldchatmsgid')
            ->where('cp.kind', 'confirm_reply')
            ->where('cp.msgid', $msgId)
            ->get(['cp.chatmsgid', 'cp.heldchatmsgid', 'cp.answer', 'cp.answered_at', 'held.chatid', 'held.userid']);

        foreach ($questions as $question) {
            if ($question->answered_at !== null && $question->answer !== 'yes') {
                continue;
            }

            if ($question->answered_at === null) {
                DB::table('chat_prompts')
                    ->where('chatmsgid', $question->chatmsgid)
                    ->whereNull('answered_at')
                    ->update(['answer' => 'expired', 'answered_at' => now(), 'answered_via' => 'post_gone']);
            }

            // The messages the question holds, or held: up to the answer, with the same clock slack as
            // ChatMessage::heldByQuestionSql.
            $heldUntil = ($question->answered_at === null ? now() : Carbon::parse($question->answered_at))->addSeconds(2);
            DB::table('chat_messages')
                ->where('chatid', $question->chatid)
                ->where('userid', $question->userid)
                ->where('id', '>=', $question->heldchatmsgid)
                ->where('processingrequired', 1)
                ->where('date', '<=', $heldUntil)
                ->update([
                    'processingrequired' => 0,
                    'processingsuccessful' => 0,
                    'processingfailreason' => ChatMessage::PROCESSFAIL_POST_GONE,
                ]);
        }
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

            $spooler->spool($mail, $recipientEmail);
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
        $existingRow = DB::table('users_emails')
            ->where('userid', $userId)
            ->where('email', $canon) // LOWER() is redundant: email is utf8mb4_unicode_ci
            ->first(['validated']);

        // Only skip re-sending the verification when the address is already CONFIRMED. If it is on
        // the account but unvalidated (validated IS NULL), fall through and (re)send the confirm
        // mail. Otherwise the user is stuck forever: every "save" finds the address "existing",
        // makes it primary and returns WITHOUT sending, so an unconfirmed address can never be
        // validated and the user keeps clicking "verify" with no mail arriving.
        if ($existingRow && $existingRow->validated !== null) {
            // Already the user's CONFIRMED email — just make it primary.
            DB::table('users_emails')
                ->where('userid', $userId)
                ->where('email', $canon) // LOWER() is redundant: email is utf8mb4_unicode_ci
                ->update(['preferred' => 1]);

            DB::table('users_emails')
                ->where('userid', $userId)
                ->where('email', '!=', $canon)
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
            ->where('validatetime', '>', now()->subSeconds(600))
            ->value('validatekey');

        $key = $recentKey;

        if (! $key) {
            // SECURITY: use a CSPRNG, not uniqid() (a time-based, guessable value). This key is a
            // bearer credential - a successful guess confirms ownership of the email and can trigger
            // an account merge in the Go PatchSession consumer. 128 bits of entropy makes it
            // unguessable, and validatetime lets the consumer expire it.
            $key = bin2hex(random_bytes(16));
            DB::table('users_emails')->upsert(
                [
                    'email' => $email,
                    'canon' => $canon,
                    'validatekey' => $key,
                    'backwards' => strrev($canon),
                    'validatetime' => now(),
                ],
                ['email'],
                ['validatekey' => $key, 'validatetime' => now()]
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

        $spooler->spool($mail, $email, 'verify_email');

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

        $spooler->spool($mail, $recipients);

        Log::info('Sent refer to support email', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Email the central spam team when a user reports a chat with someone they
     * share no Freegle group with (so it can't be routed to a community's mods).
     */
    protected function handleEmailChatSpamReport(
        array $data,
        EmailSpoolerService $spooler,
        bool $shouldSpool
    ): void {
        $chatId = (int) ($data['chatid'] ?? 0);
        $userId = (int) ($data['userid'] ?? 0);

        if ($chatId === 0 || $userId === 0) {
            throw new \RuntimeException('email_chat_spam_report requires chatid and userid');
        }

        $chat = DB::table('chat_rooms')->where('id', $chatId)->first();
        if (! $chat) {
            Log::warning("Chat not found for email_chat_spam_report: {$chatId}");
            return;
        }

        $user = User::find($userId);
        if (! $user) {
            Log::warning("User not found for email_chat_spam_report: {$userId}");
            return;
        }

        $otherUserId = $chat->user1 == $userId ? $chat->user2 : $chat->user1;
        $otherUser = $otherUserId ? User::find($otherUserId) : null;
        $otherUserName = $otherUser ? ($otherUser->fullname ?: 'Unknown') : 'Unknown';

        $mail = new ChatSpamReportMail(
            reporterName: $user->fullname ?: 'Unknown',
            reporterId: $userId,
            otherUserName: $otherUserName,
            otherUserId: (int) ($otherUserId ?? 0),
            chatId: $chatId,
            reason: (string) ($data['reason'] ?? ''),
            comment: (string) ($data['comment'] ?? ''),
        );

        $recipients = array_map('trim', explode(',', config('freegle.mail.spam_addr')));
        $spooler->spool($mail, $recipients);

        Log::info('Sent chat spam report email', [
            'reporter_id' => $userId,
            'chat_id' => $chatId,
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
     * A Freegle-wide block keyword was just created: apply it to the last 24 hours
     * of chat messages and posts, which arrived before it existed. Queued by the Go
     * API's CreateConcernKeyword. A keyword that no longer qualifies (deleted, or
     * not a global block) is a no-op, so a retried task cannot act on the wrong row.
     */
    protected function handleConcernKeywordBackfill(array $data): void
    {
        $keywordId = (int) ($data['keyword_id'] ?? 0);
        if ($keywordId === 0) {
            throw new \RuntimeException('concern_keyword_backfill requires keyword_id');
        }

        $keyword = DB::table('concern_keywords')->where('id', $keywordId)->first();
        if (!$keyword || $keyword->action !== 'block' || $keyword->scope !== 'global') {
            Log::info('Concern keyword backfill skipped: not a global block keyword', ['keyword_id' => $keywordId]);
            return;
        }

        $result = app(BlockedKeywordBackfillService::class)->run(now()->subDay(), [$keywordId]);

        Log::info('Concern keyword backfill complete', [
            'keyword_id' => $keywordId,
            'keyword' => $keyword->keyword,
            'chat_matched' => $result['chat']['matched'],
            'chat_changed' => $result['chat']['changed'],
            'posts_matched' => $result['posts']['matched'],
            'posts_changed' => $result['posts']['changed'],
        ]);
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

        $spooler->spool($bccMail, $bccAddress);

        Log::info('Sent BCC copy of mod stdmsg', [
            'action' => $action,
            'bcc' => $bccAddress,
            'byuser' => $byUser,
            'groupid' => $groupId,
        ]);
    }

    /**
     * Partners that take our Offer feed through the same create/delete API, keyed by config
     * name. Each is sent independently; one with no key configured is skipped.
     */
    private const POST_FEEDS = [
        'freebie_alerts' => 'Freebie Alerts',
        'yeybu'          => 'Yeybu',
    ];

    /**
     * A partner that times out or returns a 5xx is retried, one partner at a time, with the
     * delay doubling from a minute up to this. The retry is a new task row whose created_at
     * is when it falls due.
     */
    private const POST_FEED_RETRY_MAX_DELAY_SECONDS = 1800;

    /**
     * Partner outages are usually short, so a failure is only reported as an error once it
     * has gone on this long. Before then each failure is a warning.
     */
    private const POST_FEED_ALERT_AFTER_SECONDS = 3600;

    /**
     * After this long the post is given up on and reported.
     */
    private const POST_FEED_GIVE_UP_AFTER_SECONDS = 86400;

    /**
     * The partner post feeds that have a key configured. A retry carries the one partner it
     * is for, and goes only to that one.
     *
     * @return array<string, array{name: string, url: string, key: string}>
     */
    private function configuredPostFeeds(array $data = []): array
    {
        $feeds = [];

        foreach (self::POST_FEEDS as $configKey => $name) {
            if (isset($data['feed']) && $data['feed'] !== $configKey) {
                continue;
            }

            $apiKey = config("freegle.{$configKey}.api_key");
            if (! empty($apiKey)) {
                $feeds[$configKey] = [
                    'name' => $name,
                    'url'  => rtrim((string) config("freegle.{$configKey}.api_url"), '/'),
                    'key'  => $apiKey,
                ];
            }
        }

        return $feeds;
    }

    /**
     * What the partners are sent for a post, or null when the post should not be on their
     * feeds now.
     *
     * V1 parity with FreebieAlerts::add() — only outstanding, approved Offers with a
     * location. TrashNothing messages are skipped (TN syncs directly). It is worked out at
     * send time, so a retry that falls due after the post was taken, withdrawn or deleted
     * sends nothing.
     */
    private function postFeedPayload(int $msgId): ?array
    {
        // Only outstanding Offers (no outcome yet).
        $msg = DB::table('messages')->where('id', $msgId)->whereNull('deleted')->first();
        if (! $msg || $msg->type !== 'Offer') {
            return null;
        }

        $hasOutcome = DB::table('messages_outcomes')->where('msgid', $msgId)->exists();
        if ($hasOutcome) {
            return null;
        }

        // Defense-in-depth: skip clearance/bulk-offer posts even if somehow enqueued.
        $isClearance = DB::table('messages_bulk_items')->where('msgid', $msgId)->exists();
        if ($isClearance) {
            Log::info('Skipping freebie_alerts_add for clearance post', ['msgid' => $msgId]);
            return null;
        }

        // Skip TrashNothing messages — TN syncs to the partners directly.
        if ($msg->sourceheader && str_starts_with($msg->sourceheader, 'TN-')) {
            return null;
        }

        $group = DB::table('messages_groups')
            ->where('msgid', $msgId)
            ->where('collection', 'Approved')
            ->where('deleted', 0)
            ->first();
        if (! $group) {
            return null;
        }

        if (! $msg->lat || ! $msg->lng) {
            return null;
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

        return [
            'id' => $msgId,
            'title' => $msg->subject,
            'description' => $body,
            'latitude' => $msg->lat,
            'longitude' => $msg->lng,
            'images' => $images,
            // The partners expect "YYYY-MM-DD HH:MM:SS"; arrival comes back with microseconds.
            'created_at' => Carbon::parse($group->arrival)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Add a post to the partner post feeds (freebiealerts.app, yeybu.com).
     */
    protected function handleFreebieAlertsAdd(array $data): void
    {
        $msgId = (int) ($data['msgid'] ?? 0);
        if ($msgId === 0) {
            throw new \RuntimeException('freebie_alerts_add requires msgid');
        }

        $feeds = $this->configuredPostFeeds($data);
        if (empty($feeds)) {
            Log::debug('No post feed API key configured, skipping add', ['msgid' => $msgId]);
            return;
        }

        $payload = $this->postFeedPayload($msgId);
        if ($payload === null) {
            return;
        }

        foreach ($feeds as $configKey => $feed) {
            $this->sendToPostFeed($configKey, $feed, $msgId, 'add', '/freegle/post/create', $payload, $data);
        }
    }

    /**
     * Remove a post from the partner post feeds.
     *
     * V1 parity with FreebieAlerts::remove().
     */
    protected function handleFreebieAlertsRemove(array $data): void
    {
        $msgId = (int) ($data['msgid'] ?? 0);
        if ($msgId === 0) {
            throw new \RuntimeException('freebie_alerts_remove requires msgid');
        }

        $feeds = $this->configuredPostFeeds($data);
        if (empty($feeds)) {
            Log::debug('No post feed API key configured, skipping remove', ['msgid' => $msgId]);
            return;
        }

        // A retried remove can fall due after the post came back - reposted, or its outcome
        // withdrawn - and a fresh add has gone out since. Deleting it then would take a live
        // post off the feed. Only retries are checked: a first remove is queued as the post
        // goes, and its change may not be readable here yet.
        if (isset($data['feed']) && $this->postFeedPayload($msgId) !== null) {
            Log::info('Skipping retried post feed remove, the post is live again', [
                'feed' => $data['feed'],
                'msgid' => $msgId,
            ]);
            return;
        }

        foreach ($feeds as $configKey => $feed) {
            $this->sendToPostFeed($configKey, $feed, $msgId, 'remove', "/freegle/post/{$msgId}/delete", null, $data);
        }
    }

    /**
     * POST one add or remove to one partner. A failure is never thrown, so the other
     * partners still get it. A remove has no body.
     *
     * A timeout, connection failure, 408, 429 or 5xx is retried later for this partner
     * alone. Any other refusal is our request being wrong, so it is reported at once and
     * not retried - except a 404 on a remove, which means the partner never had the post.
     *
     * @param array{name: string, url: string, key: string} $feed
     */
    private function sendToPostFeed(string $configKey, array $feed, int $msgId, string $action, string $path, ?array $payload, array $data): void
    {
        $name = $feed['name'];

        try {
            $request = \Illuminate\Support\Facades\Http::withHeaders([
                'Content-type' => 'application/json',
                'Key' => $feed['key'],
            ])->timeout(60);

            $response = $payload === null
                ? $request->send('POST', $feed['url'] . $path)
                : $request->post($feed['url'] . $path, $payload);
        } catch (\Throwable $e) {
            $this->retryPostFeed($configKey, $name, $msgId, $action, $data, $e->getMessage());
            return;
        }

        $status = $response->status();

        if ($response->successful()) {
            Log::info("Post feed {$action} succeeded", ['feed' => $name, 'msgid' => $msgId]);
        } elseif ($status >= 500 || $status === 408 || $status === 429) {
            $this->retryPostFeed($configKey, $name, $msgId, $action, $data, (string) $status);
        } elseif ($action === 'remove' && $status === 404) {
            Log::info('Post feed remove found nothing to remove', ['feed' => $name, 'msgid' => $msgId]);
        } else {
            Log::warning("Post feed {$action} failed", [
                'feed' => $name,
                'msgid' => $msgId,
                'status' => $status,
                'body' => $response->body(),
            ]);
            if (app()->bound('sentry')) {
                app('sentry')->captureMessage("{$name} {$action} failed for message {$msgId}: {$status}");
            }
        }
    }

    /**
     * Queue another try of one add or remove for one partner, after a failure that may be
     * temporary. The failure is a warning until it has gone on for an hour, an error once
     * then, and an error again when it is given up on after a day.
     */
    private function retryPostFeed(string $configKey, string $name, int $msgId, string $action, array $data, string $reason): void
    {
        $firstFailedAt = isset($data['first_failed_at']) ? Carbon::parse($data['first_failed_at']) : now();
        $failingFor = now()->getTimestamp() - $firstFailedAt->getTimestamp();
        $retries = (int) ($data['retries'] ?? 0);
        $alerted = ! empty($data['alerted']);
        $context = [
            'feed' => $name,
            'msgid' => $msgId,
            'reason' => $reason,
            'retries' => $retries,
            'first_failed_at' => $firstFailedAt->toDateTimeString(),
        ];

        if ($failingFor >= self::POST_FEED_GIVE_UP_AFTER_SECONDS) {
            Log::error("Post feed {$action} given up after a day of failures", $context);
            if (app()->bound('sentry')) {
                app('sentry')->captureMessage("{$name} {$action} given up for message {$msgId} after a day: {$reason}");
            }
            return;
        }

        if (! $alerted && $failingFor >= self::POST_FEED_ALERT_AFTER_SECONDS) {
            Log::error("Post feed {$action} still failing after an hour", $context);
            if (app()->bound('sentry')) {
                app('sentry')->captureMessage("{$name} {$action} still failing for message {$msgId} after an hour: {$reason}");
            }
            $alerted = true;
        } else {
            Log::warning("Post feed {$action} failed, will retry", $context);
        }

        $delay = min(60 * (2 ** min($retries, 10)), self::POST_FEED_RETRY_MAX_DELAY_SECONDS);

        DB::table('background_tasks')->insert([
            'task_type' => $action === 'add'
                ? BackgroundTask::TASK_FREEBIE_ALERTS_ADD
                : BackgroundTask::TASK_FREEBIE_ALERTS_REMOVE,
            'data' => json_encode([
                'msgid' => $msgId,
                'feed' => $configKey,
                'first_failed_at' => $firstFailedAt->toIso8601String(),
                'retries' => $retries + 1,
                'alerted' => $alerted,
            ]),
            'created_at' => DB::raw('DATE_ADD(NOW(), INTERVAL ' . (int) $delay . ' SECOND)'),
        ]);
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

    protected function handleUserForget(array $data): void
    {
        $userId = isset($data['user_id']) ? (int) $data['user_id'] : NULL;
        $reason = $data['reason'] ?? 'Support purge';

        if (! $userId) {
            throw new \RuntimeException('user_forget requires user_id');
        }

        $service = app(UserManagementService::class);
        $service->forgetUser($userId, $reason);

        Log::info('User forgotten via background task', [
            'user_id' => $userId,
            'reason'  => $reason,
        ]);
    }

    protected function handleTnSyncCommand(array $data): void
    {
        $args = [];

        if (!empty($data['from'])) {
            $args['--from'] = (string) $data['from'];
        }

        if (!empty($data['to'])) {
            $args['--to'] = (string) $data['to'];
        }

        if (!empty($data['run_id'])) {
            $args['--run-id'] = (string) $data['run_id'];
        }

        if (isset($data['dry_run']) && (bool) $data['dry_run']) {
            $args['--dry-run'] = true;
        }

        if (isset($data['local_testing']) && (bool) $data['local_testing']) {
            $args['--local-testing'] = true;
        }

        $exitCode = Artisan::call('tn:sync', $args);

        Log::info('Processed tn_sync_command task', [
            'exit_code' => $exitCode,
            'from' => $args['--from'] ?? null,
            'to' => $args['--to'] ?? null,
            'run_id' => $args['--run-id'] ?? null,
            'dry_run' => $args['--dry-run'] ?? null,
        ]);

        if ($exitCode !== 0) {
            throw new \RuntimeException("tn:sync failed with exit code {$exitCode}");
        }
    }

    /**
     * Start, stop or close a UX experiment for the experiment routes (PATCH
     * /modtools/ux/experiments/:id). The Go API only queues; the service applies the concurrency
     * cap, the 24-hour end and the config key the client reads, so those rules live once.
     */
    private function handleUxExperiment(array $data): void
    {
        $id = (int) ($data['id'] ?? 0);
        $action = (string) ($data['action'] ?? '');
        $service = app(UxExperimentService::class);
        try {
            match ($action) {
                'start' => $service->start($id),
                'stop' => $service->stop($id, (string) ($data['reason'] ?: 'Stopped from ModTools.')),
                'close' => $service->close($id, $data['cleanup_pr'] ?: null),
                default => throw new \RuntimeException("Unknown ux_experiment action: {$action}"),
            };
        } catch (UxExperimentRefused $e) {
            // The rules said no (the cap, a second experiment on the flow, too little power, a
            // status that does not allow it). That is an answer for the person who clicked, not a
            // fault: say so on the experiment, where the experiment routes show it, and finish the task.
            // Trying again would only be refused again.
            $service->recordRefusal($id, $action, $e->getMessage());
            Log::info('UX experiment action refused', ['id' => $id, 'action' => $action, 'why' => $e->getMessage()]);

            return;
        }
        Log::info('UX experiment action applied', ['id' => $id, 'action' => $action]);
    }
}
