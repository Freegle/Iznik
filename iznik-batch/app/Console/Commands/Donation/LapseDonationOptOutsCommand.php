<?php

namespace App\Console\Commands\Donation;

use App\Models\User;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * "Don't ask me to donate" lasts a year (users.settings.nodonationasksuntil, set by the API
 * when the switch is turned on). This keeps the stored switch in step with that date:
 *
 *   1. an opt-out with no date predates the lapse, so it is dated a year from now; and
 *   2. an opt-out whose date has passed is switched off, so every client, including app
 *      builds that only read the switch, shows asks as on again.
 *
 * The readers (User::settingsAllowDonationAsks, the Go API, the client) already treat a
 * passed date as lapsed, so this is housekeeping, not what makes the lapse take effect.
 */
#[AsCommand(name: 'donations:lapse-optouts')]
class LapseDonationOptOutsCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'donations:lapse-optouts
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Date undated "don\'t ask me to donate" opt-outs a year ahead, and switch off the ones that have lapsed.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        return $this->runWithLogging(function () use ($dryRun) {
            $now = now()->utc();
            $nowIso = $now->format('Y-m-d\TH:i:s\Z');
            $untilIso = $now->copy()->addDays(User::DONATION_ASK_OPTOUT_DAYS)->format('Y-m-d\TH:i:s\Z');

            $optedOut = fn () => DB::table('users')->where('settings->nodonationasks', TRUE);

            $undated = $optedOut()->whereNull('settings->nodonationasksuntil');

            // RFC 3339 UTC strings in one format compare in date order.
            $lapsed = $optedOut()
                ->whereNotNull('settings->nodonationasksuntil')
                ->where('settings->nodonationasksuntil', '<=', $nowIso);

            if ($dryRun) {
                $this->info(sprintf('DRY RUN: would date %d opt-out(s) to %s and lapse %d.',
                    $undated->count(), $untilIso, $lapsed->count()));

                return Command::SUCCESS;
            }

            $dated = $undated->update(['settings->nodonationasksuntil' => $untilIso]);

            $lapsedCount = $lapsed->update([
                'settings->nodonationasks' => FALSE,
                'settings->nodonationasksuntil' => NULL,
            ]);

            $this->info("Dated {$dated} opt-out(s) to {$untilIso}; lapsed {$lapsedCount}.");

            return Command::SUCCESS;
        });
    }
}
