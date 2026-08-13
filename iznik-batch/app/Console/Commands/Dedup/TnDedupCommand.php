<?php

namespace App\Console\Commands\Dedup;

use App\Database\Expressions\Alias;
use App\Database\Expressions\Count;
use App\Database\Expressions\Min;
use App\Database\Expressions\Value;
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
        $duplicates = DB::table('messages')
            ->select('tnpostid')
            ->addSelect(new Alias(new Min('id'), 'canonical_id'))
            ->addSelect(new Alias(new Count(), 'cnt'))
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
                        // Move messages_groups rows to canonical message. Use INSERT IGNORE
                        // (via insertOrIgnoreUsing) in case the canonical already has a row
                        // for this group - messages_groups has a unique key on (msgid,
                        // groupid) and we don't want a duplicate to abort the whole merge.
                        $sourceRows = DB::table('messages_groups')
                            ->select([Value::of($dup->canonical_id), 'groupid', 'collection', 'arrival', 'autoreposts', 'msgtype'])
                            ->where('msgid', $dupeId);

                        DB::table('messages_groups')->insertOrIgnoreUsing(
                            ['msgid', 'groupid', 'collection', 'arrival', 'autoreposts', 'msgtype'],
                            $sourceRows
                        );

                        // Move messages_history rows. messages_history has a unique key on
                        // (msgid, groupid); IGNORE there means "skip a row that would
                        // collide with one the canonical message already has for the same
                        // group, move the rest". The query builder's update() has no IGNORE
                        // modifier, so the same net effect is expressed structurally: only
                        // update rows where no such collision would occur. MySQL forbids a
                        // subquery in an UPDATE's WHERE from directly referencing the table
                        // being updated (error 1093), so the self-reference is forced
                        // through a derived table (fromSub) - the documented MySQL
                        // workaround - keeping this a single atomic UPDATE statement.
                        DB::table('messages_history')
                            ->where('msgid', $dupeId)
                            ->whereNotExists(function ($query) use ($dup) {
                                $query->select(Value::of(1))
                                    ->fromSub(function ($sub) use ($dup) {
                                        $sub->select('groupid')
                                            ->from('messages_history')
                                            ->where('msgid', $dup->canonical_id);
                                    }, 'existing')
                                    ->whereColumn('existing.groupid', 'messages_history.groupid');
                            })
                            ->update(['msgid' => $dup->canonical_id]);

                        // Move messages_postings rows. Unlike the two tables above,
                        // messages_postings has no unique key at all, so nothing there can
                        // ever raise the duplicate-key error IGNORE exists to suppress - a
                        // plain update() is exactly equivalent.
                        DB::table('messages_postings')
                            ->where('msgid', $dupeId)
                            ->update(['msgid' => $dup->canonical_id]);

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
