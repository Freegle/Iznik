<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-off backfill: demote users whose users.systemrole is 'Moderator' but who
 * hold no Owner/Moderator group membership back to 'User'. Mirrors V1
 * User::updateSystemRole, which the Go membership-removal rewrite had dropped.
 * The ongoing fix now lives in the Go API (user.SyncSystemRole on leave/ban);
 * this command clears the ~800 rows that accumulated while it was missing.
 */
class BackfillModeratorRolesCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'users:backfill-moderator-roles
                            {--dry-run : Show how many would change without updating}';

    protected $description = 'Demote stale Moderator systemroles (systemrole=Moderator with no Owner/Moderator membership) back to User';

    public function handle(UserManagementService $service): int
    {
        $this->registerShutdownHandlers();

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($service, $dryRun) {
            Log::info('Starting stale Moderator systemrole backfill', ['dry_run' => $dryRun]);
            $this->info('Reconciling stale Moderator systemroles...');

            $stats = $service->backfillModeratorSystemRoles($dryRun);

            $verb = $dryRun ? 'Would demote' : 'Demoted';
            $this->info("{$verb} {$stats['demoted']} stale Moderator systemrole(s) to User.");
            Log::info('Stale Moderator systemrole backfill complete', $stats);

            return Command::SUCCESS;
        });
    }
}
