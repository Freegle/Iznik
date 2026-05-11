<?php

namespace App\Console\Commands\Group;

use App\Mail\Group\AlertNoMessagesMail;
use App\Models\Group;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AlertNoMessagesCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'groups:alert-no-messages
                            {--days=7 : Threshold in days with no messages before alerting}
                            {--dry-run : Show which groups would trigger an alert without sending}';

    protected $description = 'Alert geeks about Freegle groups that have not received messages recently';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no email will be sent.');
        }

        return $this->runWithLogging(function () use ($days, $dryRun) {
            $groups = DB::table('groups')
                ->where('type', Group::TYPE_FREEGLE)
                ->where('onhere', 1)
                ->where('nameshort', 'NOT LIKE', '%playground%')
                ->orderByRaw('LOWER(nameshort)')
                ->select(['id', 'nameshort'])
                ->get();

            $stale = [];

            foreach ($groups as $group) {
                $row = DB::table('messages_groups')
                    ->where('groupid', $group->id)
                    ->selectRaw('DATEDIFF(NOW(), MAX(arrival)) AS latest')
                    ->first();

                $daysSince = $row->latest ?? null;

                if ($daysSince === null || $daysSince > $days) {
                    $stale[] = "{$group->nameshort} — last message "
                        . ($daysSince !== null ? "{$daysSince} days ago" : 'never')
                        . "\r\n";
                }
            }

            $count = count($stale);
            $this->info("Found {$count} group(s) with no messages in the last {$days} day(s).");

            if ($count > 0 && !$dryRun) {
                $body = "The following groups are on Iznik but haven't received any messages recently. "
                    . "Either they are inactive or don't have modtools@modtools.org on there on individual emails.\r\n\r\n"
                    . implode('', $stale);

                $geeksAddr = config('freegle.mail.geeks_addr');

                Mail::send(new AlertNoMessagesMail($geeksAddr, $count, $body));
            }

            return Command::SUCCESS;
        });
    }
}
