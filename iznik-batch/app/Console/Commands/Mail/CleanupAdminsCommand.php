<?php

namespace App\Console\Commands\Mail;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupAdminsCommand extends Command
{
    protected $signature = 'mail:admin:cleanup';

    protected $description = 'Delete admins that were left pending and never sent';

    /**
     * Days after which a pending admin is deleted.
     */
    private const STALE_PENDING_DAYS = 31;

    public function handle(): int
    {
        $cutoff = now()->subDays(self::STALE_PENDING_DAYS);

        $deleted = DB::table('admins')
            ->where('pending', 1)
            ->whereNull('complete')
            ->where('created', '<', $cutoff)
            ->delete();

        if ($deleted > 0) {
            $this->info("Deleted {$deleted} stale pending admin(s) older than " . self::STALE_PENDING_DAYS . ' days.');
        }

        return Command::SUCCESS;
    }
}
