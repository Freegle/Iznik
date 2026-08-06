<?php

namespace App\Console\Commands\Dedup;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TnDedupCommand extends Command
{
    protected $signature = 'dedup:tn {--dry-run : Report which messages would be merged without changing the database}';
    protected $description = 'Merge duplicate Trash Nothing cross-posts by tnpostid';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && !config('freegle.dedup.tn_enabled')) {
            $this->error('dedup:tn is disabled. Set TN_DEDUP_ENABLED=true to enable, or pass --dry-run to preview.');
            return self::FAILURE;
        }

        // Find tnpostids with multiple message IDs.
        // keep-raw: MIN(id)/COUNT(*) are aggregates with aliases in a multi-row SELECT list
        // under GROUP BY; no builder method projects a named aggregate column, and both
        // aliases (canonical_id, cnt) are read back below via $dup->canonical_id / $dup->cnt.
        $duplicates = DB::table('messages')
            ->select('tnpostid', DB::raw('MIN(id) as canonical_id'), DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('tnpostid')
            ->where('tnpostid', '!=', '')
            ->whereNull('deleted')
            ->groupBy('tnpostid')
            ->having('cnt', '>', 1)
            ->get();

        $merged = 0;

        foreach ($duplicates as $dup) {
            $duplicateIds = DB::table('messages')
                ->where('tnpostid', $dup->tnpostid)
                ->where('id', '!=', $dup->canonical_id)
                ->whereNull('deleted')
                ->pluck('id');

            foreach ($duplicateIds as $dupeId) {
                if ($dryRun) {
                    $this->line("[dry-run] Would merge message {$dupeId} into {$dup->canonical_id} (tnpostid: {$dup->tnpostid})");
                } else {
                    DB::transaction(function () use ($dup, $dupeId) {
                        // Move messages_groups rows to canonical message.
                        // Use INSERT IGNORE in case the canonical already has a row for this group.
                        // keep-raw: INSERT ... SELECT with IGNORE. insertOrIgnore() only accepts an
                        // array of values, not a SELECT source, so it cannot express this without
                        // first pulling the source rows into PHP and losing the atomic IGNORE guard.
                        DB::statement('
                            INSERT IGNORE INTO messages_groups (msgid, groupid, collection, arrival, autoreposts, msgtype)
                            SELECT ?, groupid, collection, arrival, autoreposts, msgtype
                            FROM messages_groups WHERE msgid = ?
                        ', [$dup->canonical_id, $dupeId]);

                        // Move messages_history rows.
                        // keep-raw: UPDATE IGNORE has no query-builder equivalent (update() emits a
                        // plain UPDATE with no IGNORE modifier).
                        DB::statement('
                            UPDATE IGNORE messages_history SET msgid = ? WHERE msgid = ?
                        ', [$dup->canonical_id, $dupeId]);

                        // Move messages_postings rows.
                        // keep-raw: UPDATE IGNORE has no query-builder equivalent (update() emits a
                        // plain UPDATE with no IGNORE modifier).
                        DB::statement('
                            UPDATE IGNORE messages_postings SET msgid = ? WHERE msgid = ?
                        ', [$dup->canonical_id, $dupeId]);

                        // Update chat_messages references.
                        DB::table('chat_messages')
                            ->where('refmsgid', $dupeId)
                            ->update(['refmsgid' => $dup->canonical_id]);

                        // Delete duplicate's messages_groups rows and soft-delete the message.
                        DB::table('messages_groups')->where('msgid', $dupeId)->delete();
                        DB::table('messages')->where('id', $dupeId)->update([
                            'deleted' => now(),
                            'messageid' => null,
                        ]);
                    });

                    Log::info("TN dedup: merged message {$dupeId} into {$dup->canonical_id} (tnpostid: {$dup->tnpostid})");
                }

                $merged++;
            }
        }

        if ($dryRun) {
            $this->info("[dry-run] Would merge {$merged} duplicate TN posts. No changes made.");
        } else {
            $this->info("Merged {$merged} duplicate TN posts.");
        }

        return self::SUCCESS;
    }
}
