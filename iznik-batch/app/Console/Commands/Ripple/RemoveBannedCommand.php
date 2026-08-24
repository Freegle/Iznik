<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\ExpandService;
use Illuminate\Console\Command;

/**
 * ripple:remove-banned — one-off remediation for the window before rippling honoured
 * users_banned. Rippling used to re-join a poster to (and re-insert their post into) a
 * group they were banned from, silently undoing a moderator's ban. The join sites now
 * carry a users_banned guard; this cleans up the members already wrongly (re-)joined.
 *
 * For every ripple-join (memberships.rippled=1) into a group the member is banned from,
 * it soft-deletes that member's rippled-in post copies still live there and removes the
 * ripple-join membership. Organic memberships are never touched. Galera-safe: one row per
 * statement. Defaults to a dry run; pass --commit to write.
 */
class RemoveBannedCommand extends Command
{
    protected $signature = 'ripple:remove-banned
                            {--commit : Actually remove; without this it only reports what it would do}
                            {--user= : Restrict to a single user ID}
                            {--limit=100000 : Max banned ripple-join pairs to process}';

    protected $description = 'Remove ripple-join memberships (and rippled-in posts) for users banned from the target group';

    public function handle(ExpandService $service): int
    {
        $dryRun = !$this->option('commit');
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;
        $limit = (int) $this->option('limit');

        $stats = ['pairs' => 0, 'memberships_removed' => 0, 'posts_pulled' => 0];
        $service->pullBannedRippleMemberships($userId, $limit, $dryRun, $stats);

        $this->info(sprintf(
            '%s %d banned ripple-join pair(s): %s %d ripple-join membership(s) and %s %d rippled-in post copy(ies).',
            $dryRun ? 'Would remediate' : 'Remediated',
            $stats['pairs'],
            $dryRun ? 'would remove' : 'removed',
            $stats['memberships_removed'],
            $dryRun ? 'would pull' : 'pulled',
            $stats['posts_pulled'],
        ));

        if ($dryRun) {
            $this->comment('Dry run — no changes written. Re-run with --commit to apply.');
        }

        return Command::SUCCESS;
    }
}
