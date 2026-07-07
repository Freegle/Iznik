<?php

namespace App\Console\Commands\Message;

use App\Services\ContentCheckService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ContentCheckCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'messages:contentcheck
                            {--dry-run : Show decisions without making changes}
                            {--audit  : Scan Pending + Approved messages and report disagreements (read-only)}
                            {--group= : Restrict --audit to a single group ID}
                            {--limit=500 : Max rows per collection to examine in --audit mode (0 = no limit)}
                            {--since= : In --audit mode, only consider messages with arrival within the last N days}';

    protected $description = 'Process unprocessed pending messages through content checks';

    public function handle(ContentCheckService $service): int
    {
        $this->registerShutdownHandlers();

        if ($this->option('audit')) {
            return $this->runAudit($service);
        }

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        Log::info('ContentCheck: starting run', ['dry_run' => $dryRun]);
        $this->info('Processing unprocessed pending messages...');

        $stats = $service->processUnprocessed($dryRun);

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Approved: {$stats['approved']}, Kept pending: {$stats['kept_pending']}, Blocked: {$stats['blocked']}, Errors: {$stats['errors']}");

        if ($stats['errors'] > 0) {
            $this->warn("Errors: {$stats['errors']}");
        }

        Log::info('ContentCheck: run complete', $stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function runAudit(ContentCheckService $service): int
    {
        $groupid   = $this->option('group') !== null ? (int) $this->option('group') : null;
        $limit     = (int) $this->option('limit');
        $sinceDays = $this->option('since') !== null ? (int) $this->option('since') : null;

        $scope  = $groupid ? "group #{$groupid}" : 'all groups';
        $window = $sinceDays !== null && $sinceDays > 0 ? " within the last {$sinceDays} days" : '';
        $this->info("AUDIT MODE (read-only) — scanning Pending + Approved messages across {$scope}{$window}, limit {$limit} per collection...");

        $disagreements = $service->auditExisting($groupid, $limit, $sinceDays);

        if (empty($disagreements)) {
            $this->info('No disagreements found.');
            return Command::SUCCESS;
        }

        $shouldFlag    = array_filter($disagreements, fn ($d) => $d['type'] === 'should_flag');
        $shouldApprove = array_filter($disagreements, fn ($d) => $d['type'] === 'should_approve');

        $this->newLine();
        $this->line(sprintf(
            'Found %d disagreement(s): %d approved-but-should-flag, %d pending-but-should-approve',
            count($disagreements),
            count($shouldFlag),
            count($shouldApprove)
        ));

        if (!empty($shouldFlag)) {
            $this->newLine();
            $this->warn('=== APPROVED messages that content check would FLAG ===');
            foreach ($shouldFlag as $d) {
                $this->line(sprintf('  msg #%d  group #%d  moderated=%s', $d['msgid'], $d['groupid'], $d['is_moderated'] ? 'yes' : 'no'));
                foreach ($d['reasons'] as $r) {
                    $this->line(sprintf('    [%s] %s', $r['check'], $r['detail']));
                }
            }
        }

        if (!empty($shouldApprove)) {
            $this->newLine();
            $this->info('=== PENDING messages that content check would AUTO-APPROVE ===');
            foreach ($shouldApprove as $d) {
                $this->line(sprintf('  msg #%d  group #%d', $d['msgid'], $d['groupid']));
            }
        }

        return Command::SUCCESS;
    }
}
