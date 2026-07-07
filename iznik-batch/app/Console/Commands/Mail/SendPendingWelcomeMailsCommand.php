<?php

namespace App\Console\Commands\Mail;

use App\Console\Concerns\PreventsOverlapping;
use App\Mail\Traits\FeatureFlags;
use App\Mail\Welcome\WelcomeMail;
use App\Models\BatchEmailProgress;
use App\Models\User;
use App\Services\EmailSpoolerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPendingWelcomeMailsCommand extends Command
{
    use FeatureFlags;
    use PreventsOverlapping;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mail:welcome:send
                            {--limit=100 : Maximum welcome mails to send per run}
                            {--days=1 : Only process users added within this many days}
                            {--spool : Spool emails instead of sending directly}
                            {--dry-run : Show what would be sent without actually sending}';

    /**
     * The console command description.
     */
    protected $description = 'Send pending welcome emails to new users';

    private const JOB_TYPE = 'welcome';

    private const EMAIL_TYPE = 'Welcome';

    /**
     * Execute the console command.
     */
    public function handle(EmailSpoolerService $spooler): int
    {
        if (! $this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        try {
            return $this->doHandle($spooler);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * The actual command logic.
     */
    protected function doHandle(EmailSpoolerService $spooler): int
    {
        // Check if Welcome emails are enabled for this batch system.
        if (! self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            $this->info("Welcome emails are not enabled in iznik-batch. Set FREEGLE_MAIL_ENABLED_TYPES in .env to include 'Welcome'.");

            return Command::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $days = (int) $this->option('days');
        $spool = $this->option('spool');
        $dryRun = $this->option('dry-run');

        $this->info("Looking for pending welcome mails (limit: {$limit}, days: {$days})...");

        // Get or create progress record for welcome emails.
        $progress = BatchEmailProgress::forJob(self::JOB_TYPE);

        // If this is the first run (no last_processed_id), initialize to current max user ID.
        // This assumes all existing users were welcomed synchronously before this batch system.
        if ($progress->last_processed_id === null) {
            $maxUserId = DB::table('users')->max('id') ?? 0;
            $this->info("First run detected. Initializing last_processed_id to {$maxUserId}.");
            $progress->last_processed_id = $maxUserId;
            $progress->last_processed_at = now();
            $progress->save();

            if (! $dryRun) {
                Log::info('Welcome mail batch initialized', [
                    'last_processed_id' => $maxUserId,
                ]);
            }
        }

        $progress->markStarted();

        // Find users who need welcome mails:
        // - ID greater than last processed (not yet welcomed)
        // - Not deleted
        // - Added within the specified days (backstop to avoid processing very old users)
        // - Have at least one non-bounced email address
        $cutoffDate = now()->subDays($days);
        $lastProcessedId = $progress->last_processed_id;

        $users = User::where('id', '>', $lastProcessedId)
            ->whereNull('deleted')
            ->where('added', '>=', $cutoffDate)
            ->whereHas('emails', function ($query) {
                $query->whereNull('bounced');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->info("Found {$users->count()} users needing welcome mails (after ID {$lastProcessedId}).");

        $sent = 0;
        $skipped = 0;
        $errors = 0;
        $lastSuccessfulId = $lastProcessedId;

        foreach ($users as $user) {
            // Get the user's preferred email address.
            $email = $user->emails()
                ->whereNull('bounced')
                ->orderByDesc('preferred')
                ->orderByDesc('validated')
                ->first();

            if (! $email) {
                $this->warn("User {$user->id} has no valid email address, skipping.");
                $skipped++;
                // Still update progress even if skipped - we processed this user.
                $lastSuccessfulId = $user->id;

                continue;
            }

            if ($dryRun) {
                $this->line("Would send welcome mail to: {$email->email} (user {$user->id})");
                $sent++;
                $lastSuccessfulId = $user->id;

                continue;
            }

            try {
                $mailable = new WelcomeMail(
                    recipientEmail: $email->email,
                    password: null,
                    userId: $user->id
                );

                if ($spool) {
                    $spooler->spool($mailable, $email->email, 'welcome');
                    $this->line("Spooled welcome mail for: {$email->email}");
                } else {
                    app(\App\Services\EmailSpoolerService::class)->spool($mailable, $email->email);
                    $this->line("Sent welcome mail to: {$email->email}");
                }

                $sent++;
                $lastSuccessfulId = $user->id;

                // Update progress after each successful send.
                $progress->markProcessed($lastSuccessfulId);

                Log::info('Welcome mail sent', [
                    'user_id' => $user->id,
                    'email' => $email->email,
                    'spooled' => $spool,
                ]);
            } catch (\Exception $e) {
                $this->error("Failed to send welcome mail to {$email->email}: {$e->getMessage()}");
                Log::error('Welcome mail failed', [
                    'user_id' => $user->id,
                    'email' => $email->email,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
                // Do not update progress on error - we will retry this user next run.
                break;
            }
        }

        // Final progress update (for skipped users).
        if ($lastSuccessfulId > $lastProcessedId && ! $dryRun) {
            $progress->markProcessed($lastSuccessfulId);
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Sent', $sent],
                ['Skipped', $skipped],
                ['Errors', $errors],
                ['Last Processed ID', $lastSuccessfulId],
            ]
        );

        if ($dryRun) {
            $this->warn('Dry run - no emails were actually sent.');
        }

        return Command::SUCCESS;
    }
}
