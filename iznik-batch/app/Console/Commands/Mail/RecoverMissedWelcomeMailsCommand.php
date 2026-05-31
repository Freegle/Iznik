<?php

namespace App\Console\Commands\Mail;

use App\Mail\Traits\FeatureFlags;
use App\Mail\Welcome\WelcomeMail;
use App\Models\EmailTracking;
use App\Models\User;
use App\Services\EmailSpoolerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RecoverMissedWelcomeMailsCommand extends Command
{
    use FeatureFlags;

    private const EMAIL_TYPE = 'Welcome';

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mail:welcome:recover
                            {--start= : Start date of transition window (Y-m-d H:i:s)}
                            {--end= : End date of transition window (Y-m-d H:i:s)}
                            {--limit=100 : Maximum emails to send per run}
                            {--check-tracking : Check email_tracking table for existing welcome emails}
                            {--check-activity : Only send to users with no activity (no logins/messages)}
                            {--spool : Spool emails instead of sending directly}
                            {--dry-run : Show what would be sent without actually sending}
                            {--force : Send even if user appears to have received welcome already}';

    /**
     * The console command description.
     */
    protected $description = 'Recover and send welcome emails to users who may have been missed during the transition to batch sending';

    /**
     * Execute the console command.
     */
    public function handle(EmailSpoolerService $spooler): int
    {
        // Check if Welcome emails are enabled for this batch system.
        if (! self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            $this->info("Welcome emails are not enabled in iznik-batch. Set FREEGLE_MAIL_ENABLED_TYPES in .env to include 'Welcome'.");

            return Command::SUCCESS;
        }

        $startDate = $this->option('start');
        $endDate = $this->option('end');
        $limit = (int) $this->option('limit');
        $checkTracking = $this->option('check-tracking');
        $checkActivity = $this->option('check-activity');
        $spool = $this->option('spool');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if (! $startDate || ! $endDate) {
            $this->error('Both --start and --end dates are required.');
            $this->line("Example: php artisan mail:welcome:recover --start='2024-12-23 00:00:00' --end='2024-12-24 00:00:00'");

            return Command::FAILURE;
        }

        try {
            $start = new \DateTime($startDate);
            $end = new \DateTime($endDate);
        } catch (\Exception $e) {
            $this->error('Invalid date format. Use Y-m-d H:i:s format.');

            return Command::FAILURE;
        }

        $this->info("Searching for users created between {$startDate} and {$endDate}...");

        // Build query for users in the transition window.
        $query = User::whereNull('deleted')
            ->where('added', '>=', $start)
            ->where('added', '<=', $end)
            ->whereHas('emails', function ($q) {
                $q->whereNull('bounced');
            });

        // Optionally exclude users who have a welcome email in tracking table.
        if ($checkTracking && ! $force) {
            $query->whereDoesntHave('emailTracking', function ($q) {
                $q->where('email_type', 'welcome');
            });
        }

        // Optionally only include users with no activity.
        if ($checkActivity) {
            $query->where(function ($q) {
                // No logins since creation.
                $q->whereNull('lastaccess')
                    ->orWhereRaw('lastaccess = added');
            });
        }

        $totalUsers = $query->count();
        $this->info("Found {$totalUsers} users in transition window".
            ($checkTracking ? ' without welcome tracking record' : '').
            ($checkActivity ? ' with no activity' : '').'.');

        if ($totalUsers === 0) {
            $this->info('No users need recovery.');

            return Command::SUCCESS;
        }

        $users = $query->orderBy('id')->limit($limit)->get();

        $this->info("Processing {$users->count()} users (limit: {$limit})...");
        $this->newLine();

        $sent = 0;
        $skipped = 0;
        $errors = 0;
        $alreadyWelcomed = 0;

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

                continue;
            }

            // Double-check tracking table if requested.
            if ($checkTracking && ! $force) {
                $hasWelcome = EmailTracking::where('userid', $user->id)
                    ->where('email_type', 'welcome')
                    ->exists();

                if ($hasWelcome) {
                    $this->line("User {$user->id} already has welcome tracking record, skipping.");
                    $alreadyWelcomed++;

                    continue;
                }
            }

            // Show user details.
            $activityStatus = $user->lastaccess ? 'active' : 'no activity';
            $this->line(sprintf(
                'User %d: %s (%s) - added %s [%s]',
                $user->id,
                $email->email,
                $user->fullname ?? 'no name',
                $user->added,
                $activityStatus
            ));

            if ($dryRun) {
                $sent++;

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
                    $this->info('  -> Spooled welcome mail');
                } else {
                    app(\App\Services\EmailSpoolerService::class)->spool($mailable, $email->email);
                    $this->info('  -> Sent welcome mail');
                }

                $sent++;

                Log::info('Recovery welcome mail sent', [
                    'user_id' => $user->id,
                    'email' => $email->email,
                    'transition_recovery' => true,
                    'spooled' => $spool,
                ]);
            } catch (\Exception $e) {
                $this->error("  -> Failed: {$e->getMessage()}");
                Log::error('Recovery welcome mail failed', [
                    'user_id' => $user->id,
                    'email' => $email->email,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total in window', $totalUsers],
                ['Processed', $users->count()],
                ['Sent/Would send', $sent],
                ['Already welcomed', $alreadyWelcomed],
                ['Skipped (no email)', $skipped],
                ['Errors', $errors],
            ]
        );

        if ($dryRun) {
            $this->warn('Dry run - no emails were actually sent.');
        }

        if ($totalUsers > $limit) {
            $remaining = $totalUsers - $limit;
            $this->warn("There are {$remaining} more users to process. Run again with higher --limit or run multiple times.");
        }

        return Command::SUCCESS;
    }
}
