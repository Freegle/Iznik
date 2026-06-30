<?php

namespace App\Console\Commands\BulkOffer;

use App\Models\MessagesBulkOutreach;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Promote reviewed outreach rows from Imported to Queued for a bulk offer. This is
 * the deliberate human gate: bulkoffer:import-orgs lands rows as Imported (never
 * auto-sent); a human reviews the list, then runs this to mark which are approved
 * to email; bulkoffer:send-outreach only ever touches Queued rows.
 */
class QueueOutreachCommand extends Command
{
    protected $signature = 'bulkoffer:queue-outreach
                            {--msgid= : The bulk offer message id (required)}
                            {--id=* : Only queue these outreach row id(s); default is every Imported row for the offer}
                            {--dry-run : Report what would be queued without writing}';

    protected $description = 'Promote reviewed outreach organisations from Imported to Queued so they can be sent';

    public function handle(): int
    {
        $msgid = $this->option('msgid') !== null ? (int) $this->option('msgid') : 0;
        if (! $msgid) {
            $this->error('--msgid is required (the bulk offer messages.id).');

            return self::FAILURE;
        }

        $ids = array_filter(array_map('intval', (array) $this->option('id')));
        $dryRun = (bool) $this->option('dry-run');

        $query = DB::table('messages_bulk_outreach')
            ->where('msgid', $msgid)
            ->where('status', MessagesBulkOutreach::STATUS_IMPORTED);
        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        $count = (clone $query)->count();
        if ($count === 0) {
            $this->info('No Imported outreach rows to queue for that offer.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Would queue {$count} outreach row(s) for offer #{$msgid}.");

            return self::SUCCESS;
        }

        $query->update([
            'status' => MessagesBulkOutreach::STATUS_QUEUED,
            'updated_at' => now(),
        ]);
        $this->info("Queued {$count} outreach row(s) for offer #{$msgid}. Send with: php artisan bulkoffer:send-outreach --msgid={$msgid}");

        return self::SUCCESS;
    }
}
