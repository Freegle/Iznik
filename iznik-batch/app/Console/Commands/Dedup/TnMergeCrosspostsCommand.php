<?php

namespace App\Console\Commands\Dedup;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Collapse a set of Freegle messages that share a TrashNothing post id onto one message.
 *
 * Ingestion keeps a cross-posted TN item as a single message, so this is for sets that
 * predate that and is expected to find nothing on a healthy database. The lowest id is
 * the canonical message and the rest are merged into it.
 *
 * The referencing columns come from information_schema rather than a fixed list, so a
 * table added later is covered without anyone remembering to update this. That matters:
 * 54 tables carry a msgid, and missing one leaves rows pointing at a soft-deleted
 * message - messages_spatial in particular, whose msgid is UNIQUE and which is what the
 * browse feed reads.
 *
 * Per column: UPDATE IGNORE the copy's rows onto the canonical message, then DELETE
 * whatever did not move. A row can only fail to move because a unique key already holds
 * the canonical's equivalent, which makes the copy's row redundant.
 */
class TnMergeCrosspostsCommand extends Command
{
    protected $signature = 'tn:merge-crossposts
        {--dry-run : Report what would be merged without changing anything}
        {--limit=0 : Merge at most this many duplicate sets (0 = no limit), for running in batches}
        {--days=90 : Only merge sets whose messages arrived within this many days (0 = all time)}';

    protected $description = 'Merge pre-existing Trash Nothing cross-post copies onto one message';

    /**
     * Columns that reference messages.id but are not called msgid.
     *
     * @var array<string, string>
     */
    private const EXTRA_REFERENCES = [
        'chat_messages' => 'refmsgid',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $days = (int) $this->option('days');

        $references = $this->messageReferences();
        $this->info('Found '.count($references).' columns referencing a message id.');

        $sets = $this->duplicateSets($limit, $days);
        $this->info('Found '.count($sets).' TrashNothing post ids with more than one live message.');

        $mergedMessages = 0;

        foreach ($sets as $set) {
            $copies = DB::table('messages')
                ->where('tnpostid', $set->tnpostid)
                ->whereNull('deleted')
                ->where('id', '!=', $set->canonical_id)
                ->pluck('id');

            foreach ($copies as $copyId) {
                if ($dryRun) {
                    $this->line("[dry-run] would merge message {$copyId} into {$set->canonical_id} (tnpostid {$set->tnpostid})");
                    $mergedMessages++;

                    continue;
                }

                $this->mergeCopy((int) $copyId, (int) $set->canonical_id, $references);

                Log::info('TN cross-post merged', [
                    'copy' => $copyId,
                    'canonical' => $set->canonical_id,
                    'tnpostid' => $set->tnpostid,
                ]);

                $mergedMessages++;
            }
        }

        $this->info(($dryRun ? '[dry-run] would merge ' : 'Merged ').$mergedMessages.' duplicate messages.');

        return self::SUCCESS;
    }

    /**
     * Every (table, column) that holds a messages.id, taken from the live schema so a
     * table added after this was written is still covered.
     *
     * @return array<int, array{table: string, column: string}>
     */
    private function messageReferences(): array
    {
        $database = DB::connection()->getDatabaseName();

        $rows = DB::table('information_schema.COLUMNS')
            ->select('TABLE_NAME as table_name', 'COLUMN_NAME as column_name')
            ->where('TABLE_SCHEMA', $database)
            ->where('COLUMN_NAME', 'msgid')
            ->get();

        $references = [];

        foreach ($rows as $row) {
            // messages itself is handled separately - it is what we are merging.
            if ($row->table_name === 'messages') {
                continue;
            }

            $references[] = ['table' => $row->table_name, 'column' => $row->column_name];
        }

        foreach (self::EXTRA_REFERENCES as $table => $column) {
            $exists = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', $database)
                ->where('TABLE_NAME', $table)
                ->where('COLUMN_NAME', $column)
                ->exists();

            if ($exists) {
                $references[] = ['table' => $table, 'column' => $column];
            }
        }

        return $references;
    }

    /**
     * TN post ids held by more than one live message, lowest id as canonical.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function duplicateSets(int $limit, int $days)
    {
        $query = DB::table('messages')
            ->select('tnpostid', DB::raw('MIN(id) as canonical_id'), DB::raw('COUNT(*) as copies'))
            ->whereNotNull('tnpostid')
            ->where('tnpostid', '!=', '')
            ->whereNull('deleted')
            ->groupBy('tnpostid')
            ->having('copies', '>', 1);

        if ($days > 0) {
            // Only what can still be seen. A post outside the recent window is not in
            // messages_spatial, so it cannot appear on a feed or ripple, and merging it
            // would touch a great many rows to no visible effect. Across all time there
            // are ~656k of these sets; over 90 days, ~11k.
            $query->where('arrival', '>=', now()->subDays($days));
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * @param  array<int, array{table: string, column: string}>  $references
     */
    private function mergeCopy(int $copyId, int $canonicalId, array $references): void
    {
        DB::transaction(function () use ($copyId, $canonicalId, $references) {
            foreach ($references as $reference) {
                $table = $reference['table'];
                $column = $reference['column'];

                // Move what can move. A row that cannot is one the canonical already has
                // an equivalent of, because the only thing stopping it is a unique key.
                DB::statement(
                    "UPDATE IGNORE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?",
                    [$canonicalId, $copyId]
                );

                // Anything still pointing at the copy would be orphaned by the soft-delete
                // below - notably a messages_spatial row, which the browse feed reads and
                // whose msgid is UNIQUE, so it can never move onto an already-indexed
                // canonical message.
                DB::statement(
                    "DELETE FROM `{$table}` WHERE `{$column}` = ?",
                    [$copyId]
                );
            }

            // Clear tnpostid so the copy can never be picked as canonical again, and
            // messageid so a redelivery of the original email does not collide with it.
            DB::table('messages')->where('id', $copyId)->update([
                'deleted' => now(),
                'tnpostid' => null,
                'messageid' => null,
            ]);
        });
    }
}
