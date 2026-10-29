<?php

namespace App\Console\Commands\Membership;

use App\Console\Concerns\PreventsOverlapping;
use App\Services\TnMembershipBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Give TN posters whose posts arrived unaddressed the implicit membership ingestion now creates
 * for such a post. DRY-RUN BY DEFAULT: lists every (poster, group) pair and what would be done
 * to it, and changes nothing; pass --send to write. Safe to run again. See
 * TnMembershipBackfillService for the rules.
 */
#[AsCommand(name: 'memberships:backfill-tn')]
class BackfillTnCommand extends Command
{
    use PreventsOverlapping;

    protected $signature = 'memberships:backfill-tn
                            {--send : Write the memberships (default: dry-run report only)}';

    protected $description = 'Backfill implicit memberships for TrashNothing posts that arrived unaddressed';

    public function handle(TnMembershipBackfillService $service): int
    {
        if (!$this->acquireLock()) {
            $this->info('Already running, exiting.');

            return Command::SUCCESS;
        }

        try {
            $send = (bool) $this->option('send');
            if (!$send) {
                $this->info('DRY RUN - nothing will be written. Pass --send to apply.');
            }

            $stats = $service->backfill($send);

            $this->table(
                ['User', 'Group', 'Posts', 'Earliest arrival', 'Membership', 'Mixed', 'Loses contact', 'Action'],
                collect($stats['pairs'])->map(fn ($p) => [
                    $p['userid'],
                    $p['groupid'],
                    implode(', ', $p['msgids']),
                    $p['arrival'],
                    $p['membership'] === null ? '-' : sprintf('%d (%s, implicit %d, rippled %d)',
                        $p['membership']->id, $p['membership']->added_origin,
                        $p['membership']->implicit, $p['membership']->rippled),
                    $p['mixed'] ? 'yes' : 'no',
                    $p['losing_contact'] ? 'yes' : 'no',
                    $p['action'],
                ])->all(),
            );

            $verb = $send ? 'Done' : '[DRY RUN] Would do';
            $this->line("{$verb}, for ".count($stats['pairs'])." (poster, group) pairs from {$stats['rows']} unaddressed post rows:");
            arsort($stats['actions']);
            $this->table(['Action', 'Pairs'], collect($stats['actions'])->map(fn ($n, $a) => [$a, $n])->values()->all());

            // Reporting only: the effect of deciding contact per group instead of per person.
            $this->line("Mixed posters (an addressed post somewhere, so contactable everywhere today): {$stats['mixed_posters']}");
            $this->line("Pairs whose group's moderators lose contact once contact is per group: {$stats['pairs_losing_contact']}");

            Log::info('memberships:backfill-tn complete', [
                'send' => $send,
                'actions' => $stats['actions'],
                'rows' => $stats['rows'],
                'mixed_posters' => $stats['mixed_posters'],
                'pairs_losing_contact' => $stats['pairs_losing_contact'],
            ]);

            return Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
    }
}
