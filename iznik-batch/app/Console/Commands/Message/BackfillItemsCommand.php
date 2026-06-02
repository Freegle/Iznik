<?php

namespace App\Console\Commands\Message;

use App\Models\Message;
use App\Services\ItemService;
use App\Services\StatsGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill the messages_items links that the V2 incoming-mail migration stopped
 * creating (cutover 2026-02-04). For every OFFER/WANTED message in the date
 * range that has no item link, parse the item from its subject and create the
 * catalog row + link (same logic as V1 Message::save()). Optionally regenerate
 * the affected `stats` rows so the Weight figures recover.
 *
 * @see App\Services\ItemService
 * @see App\Services\StatsGenerationService
 */
class BackfillItemsCommand extends Command
{
    protected $signature = 'messages:backfill-items
                            {--from= : Start of inclusive range (YYYY-MM-DD), matched against messages.arrival}
                            {--to= : End of inclusive range (YYYY-MM-DD), default = today}
                            {--chunk=1000 : Number of messages to process per batch}
                            {--stats : After backfilling, regenerate the daily stats for each date in the range}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill missing messages_items links (and optionally regenerate stats) for OFFER/WANTED messages';

    public function handle(ItemService $items, StatsGenerationService $stats): int
    {
        $from = $this->option('from');
        if (! $from) {
            $this->error('--from is required (YYYY-MM-DD).');

            return Command::FAILURE;
        }

        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = $this->option('to')
            ? CarbonImmutable::parse($this->option('to'))->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $this->info(sprintf(
            '%s messages_items for OFFER/WANTED messages arriving %s .. %s',
            $dryRun ? 'Would backfill' : 'Backfilling',
            $start->toDateString(),
            $end->toDateString(),
        ));

        $linked = 0;
        $noSubject = 0;
        $scanned = 0;

        DB::table('messages')
            ->whereIn('type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->where('arrival', '>=', $start)
            ->where('arrival', '<=', $end)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages_items')
                    ->whereColumn('messages_items.msgid', 'messages.id');
            })
            ->select('messages.id', 'messages.subject')
            ->orderBy('messages.id')
            ->chunkById($chunk, function ($messages) use ($items, $dryRun, &$linked, &$noSubject, &$scanned) {
                foreach ($messages as $message) {
                    $scanned++;
                    $name = $items->extractItemName($message->subject ?? '');
                    if ($name === null) {
                        $noSubject++;

                        continue;
                    }

                    if (! $dryRun) {
                        $items->recordFromSubject($message->id, $message->subject);
                    }
                    $linked++;
                }
            }, 'id');

        $this->info(sprintf(
            'Scanned %d message(s): %d %s linked, %d skipped (no well-formed subject).',
            $scanned,
            $linked,
            $dryRun ? 'would be' : 'were',
            $noSubject,
        ));

        if (! $dryRun) {
            Log::info('messages:backfill-items', [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'scanned' => $scanned,
                'linked' => $linked,
                'no_subject' => $noSubject,
            ]);
        }

        if ($this->option('stats')) {
            $this->regenerateStats($stats, $start, $end, $dryRun);
        }

        return Command::SUCCESS;
    }

    private function regenerateStats(StatsGenerationService $stats, CarbonImmutable $start, CarbonImmutable $end, bool $dryRun): void
    {
        $startDay = $start->startOfDay();
        $endDay = $end->startOfDay();
        $days = $startDay->diffInDays($endDay) + 1;

        $this->info(sprintf(
            '%s stats for %d date(s) %s .. %s',
            $dryRun ? 'Would regenerate' : 'Regenerating',
            $days,
            $startDay->toDateString(),
            $endDay->toDateString(),
        ));

        for ($d = $startDay; $d->lte($endDay); $d = $d->addDay()) {
            $date = $d->toDateString();
            $result = $stats->generateForAllGroups($date, $dryRun);
            $this->line(sprintf(
                '  %s: %d groups, %d rows %s',
                $date,
                $result['groups'],
                $result['rows_written'],
                $dryRun ? 'would be written' : 'written',
            ));
        }
    }
}
