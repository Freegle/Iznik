<?php

namespace App\Console\Commands\User;

use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixTNNamesCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'users:fix-tn-names
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Fix display names for Trash Nothing users whose email encodes their name';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($dryRun) {
            Log::info('Starting TN name fix', ['dry_run' => $dryRun]);

            $fixed  = 0;
            $skipped = 0;

            // TN emails have the format: firstname-groupid@user.trashnothing.com.
            //
            // Matched on the address, not on backwards. That column does not hold one
            // thing: its definition is REVERSE(canon), which drops the -gNNNN suffix and
            // the domain dots, but some rows hold REVERSE(email) and some hold NULL. A
            // prefix on it reached 20.5% of Trash Nothing members and silently skipped
            // the rest. See .claude/rules/mail-and-data.md.
            // Both shapes are live: the per-group aliases are name-gNNNN@user.trashnothing.com,
            // and older rows sit directly on the bare domain. The reversed prefix this
            // replaced matched both, so the address pattern has to as well.
            $tnAddressSuffix = '%@%' . config('freegle.mail.trashnothing_domain');

            $rows = DB::table('users')
                ->join('users_emails', 'users.id', '=', 'users_emails.userid')
                ->where('users_emails.email', 'LIKE', $tnAddressSuffix)
                ->whereNull('users.firstname')
                ->whereNull('users.lastname')
                ->where(function ($q) {
                    $q->whereNull('users.fullname')
                      ->orWhere('users.fullname', 'LIKE', '%-%');
                })
                ->select('users.id', 'users.fullname', 'users_emails.email')
                ->get();

            foreach ($rows as $row) {
                if (preg_match('/^(.*)-[^-]+@/', $row->email, $matches)) {
                    $name = $matches[1];

                    Log::debug("FixTNNames: set fullname for user {$row->id} from {$row->email} => {$name}");

                    if (!$dryRun) {
                        DB::table('users')
                            ->where('id', $row->id)
                            ->update(['fullname' => $name]);
                    }

                    $fixed++;
                } else {
                    $skipped++;
                }
            }

            $verb = $dryRun ? 'would fix' : 'fixed';
            $this->info("Processed " . count($rows) . " TN users: {$verb} {$fixed}, skipped {$skipped}.");
            Log::info('TN name fix complete', [
                'candidates' => count($rows),
                'fixed'      => $fixed,
                'skipped'    => $skipped,
            ]);

            return Command::SUCCESS;
        });
    }
}
