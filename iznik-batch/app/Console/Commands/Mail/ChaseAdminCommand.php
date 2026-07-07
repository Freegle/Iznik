<?php

namespace App\Console\Commands\Mail;

use App\Console\Concerns\PreventsOverlapping;
use App\Mail\Admin\ChaseAdminMail;
use App\Models\Group;
use App\Models\User;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ChaseAdminCommand extends Command
{
    use GracefulShutdown;
    use PreventsOverlapping;

    protected $signature = "mail:admin:chase
                            {--dry-run : Show what would be sent without sending}
                            {--test-email= : Send a test email to this address instead of real moderators}
                            {--id= : Chase a specific admin by ID (ignores time thresholds)}
                            {--after=48 : Minimum hours pending before chasing (default 48)}";

    protected $description = 'Send chase emails to moderators for pending centralized admins (48h-7d)';

    /**
     * Minimum hours before first chase.
     */
    private const CHASE_AFTER_HOURS = 48;

    /**
     * Maximum days to keep chasing.
     */
    private const CHASE_UNTIL_DAYS = 7;

    /**
     * Minimum hours between chase emails for the same admin.
     */
    private const CHASE_INTERVAL_HOURS = 24;

    public function handle(): int
    {
        if (!$this->acquireLock()) {
            $this->warn('Another instance of mail:admin:chase is already running.');

            return Command::SUCCESS;
        }

        $this->registerShutdownHandlers();

        $dryRun = $this->option('dry-run');
        $testEmail = $this->option('test-email');
        $specificId = $this->option('id') ? (int) $this->option('id') : null;

        $afterHours = (int) $this->option('after');

        $admins = $specificId
            ? DB::table('admins')->where('id', $specificId)->get()
            : $this->findChasableAdmins($afterHours);

        if ($admins->isEmpty()) {
            $this->info('No pending admins need chasing.');
            $this->releaseLock();

            return Command::SUCCESS;
        }

        $this->info("Found {$admins->count()} pending admin(s) to chase.");

        $stats = ['chased' => 0, 'emails_sent' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($admins as $admin) {
            if ($this->shouldStop()) {
                $this->info('Shutdown signal received, stopping gracefully...');
                break;
            }

            $sent = $this->chaseAdmin($admin, $dryRun, $testEmail, $stats);
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Admins Chased', $stats['chased']],
                ['Emails Sent', $stats['emails_sent']],
                ['Skipped (no email)', $stats['skipped']],
                ['Errors', $stats['errors']],
            ]
        );

        $this->releaseLock();

        return Command::SUCCESS;
    }

    /**
     * Find centralized admins that are pending and due for a chase email.
     *
     * Criteria:
     * - parentid IS NOT NULL (centralized admin copy)
     * - pending = 1 (not yet approved)
     * - complete IS NULL (not yet processed)
     * - heldby IS NULL (not held for review)
     * - Created more than 48 hours ago, less than 7 days ago
     * - lastchaseup IS NULL or > 24 hours ago
     */
    protected function findChasableAdmins(int $afterHours = self::CHASE_AFTER_HOURS): \Illuminate\Support\Collection
    {
        $chaseAfter = now()->subHours($afterHours);
        $chaseUntil = now()->subDays(self::CHASE_UNTIL_DAYS);
        $chaseInterval = now()->subHours(self::CHASE_INTERVAL_HOURS);

        return DB::table('admins')
            ->whereNotNull('parentid')
            ->where('pending', 1)
            ->whereNull('complete')
            ->whereNull('heldby')
            ->where('created', '<=', $chaseAfter)
            ->where('created', '>=', $chaseUntil)
            ->where(function ($q) use ($chaseInterval) {
                $q->whereNull('lastchaseup')
                    ->orWhere('lastchaseup', '<', $chaseInterval);
            })
            ->get();
    }

    /**
     * Send chase emails for a single pending admin.
     */
    protected function chaseAdmin(object $admin, bool $dryRun, ?string $testEmail, array &$stats): int
    {
        if (!$admin->groupid) {
            Log::warning("Chase admin {$admin->id}: no groupid, skipping.");

            return 0;
        }

        $group = Group::find($admin->groupid);

        if (!$group) {
            Log::warning("Chase admin {$admin->id}: group {$admin->groupid} not found.");

            return 0;
        }

        $groupName = $group->namefull ?: $group->nameshort;
        $created = \Carbon\Carbon::parse($admin->created);
        $pendingHours = (int) abs($created->diffInHours(now()));

        $this->info("Chasing admin {$admin->id} for group {$groupName} (pending {$pendingHours}h)...");

        // If test mode, send to test email only.
        if ($testEmail) {
            return $this->sendTestChase($admin, $groupName, $pendingHours, $testEmail, $dryRun, $stats);
        }

        // Get all moderators for the group (including backup mods).
        $moderators = $this->getGroupModerators($admin->groupid);

        if ($moderators->isEmpty()) {
            $this->warn("  No moderators found for group {$groupName}.");

            return 0;
        }

        $sent = 0;

        foreach ($moderators as $mod) {
            $email = $mod->email_preferred;

            if (!$email) {
                $stats['skipped']++;

                continue;
            }

            if ($dryRun) {
                $this->info("  [DRY RUN] Would chase {$email} for admin {$admin->id}");
                $stats['emails_sent']++;
                $sent++;

                continue;
            }

            try {
                $mailable = new ChaseAdminMail(
                    $mod,
                    $admin->subject,
                    $groupName,
                    $pendingHours,
                    $admin->id
                );

                app(\App\Services\EmailSpoolerService::class)->spool($mailable);

                $stats['emails_sent']++;
                $sent++;
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error("Chase admin {$admin->id}: error sending to mod {$mod->id}: {$e->getMessage()}");
            }
        }

        // Update lastchaseup timestamp.
        if (!$dryRun && $sent > 0) {
            DB::table('admins')
                ->where('id', $admin->id)
                ->update(['lastchaseup' => now()]);
        }

        $stats['chased']++;
        $this->info("  Sent {$sent} chase email(s).");

        return $sent;
    }

    /**
     * Send a test chase email to a specific address.
     */
    protected function sendTestChase(
        object $admin,
        string $groupName,
        int $pendingHours,
        string $testEmail,
        bool $dryRun,
        array &$stats
    ): int {
        if ($dryRun) {
            $this->info("  [DRY RUN] Would send test chase to {$testEmail}");
            $stats['emails_sent']++;
            $stats['chased']++;

            return 1;
        }

        try {
            // Look up user by email address for personalisation.
            $user = User::whereHas('emails', function ($q) use ($testEmail) {
                $q->where('email', $testEmail);
            })->first();

            $mailable = new ChaseAdminMail(
                $user,
                $admin->subject,
                $groupName,
                $pendingHours,
                $admin->id
            );

            if ($user) {
                app(\App\Services\EmailSpoolerService::class)->spool($mailable);
            } else {
                app(\App\Services\EmailSpoolerService::class)->spool($mailable, $testEmail);
            }

            $stats['emails_sent']++;
            $stats['chased']++;
            $this->info("  Sent test chase to {$testEmail}");

            return 1;
        } catch (\Exception $e) {
            $stats['errors']++;
            Log::error("Chase admin {$admin->id}: error sending test to {$testEmail}: {$e->getMessage()}");

            return 0;
        }
    }

    /**
     * Get all moderators for a group, including backup moderators.
     */
    protected function getGroupModerators(int $groupId): \Illuminate\Support\Collection
    {
        return User::select('users.*')
            ->join('memberships', 'memberships.userid', '=', 'users.id')
            ->where('memberships.groupid', $groupId)
            ->where('memberships.collection', 'Approved')
            ->whereIn('memberships.role', ['Moderator', 'Owner'])
            ->whereNull('users.deleted')
            ->get();
    }
}
