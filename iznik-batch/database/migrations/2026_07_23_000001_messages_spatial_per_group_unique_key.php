<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * messages_spatial backs the public browse/map, with one row PER GROUP a message
     * is approved on - a cross-posted or rippled-out post must appear in browse on
     * each of its groups. The Go inserter (addApprovedMessageToSpatialIndex) and the
     * every-5-minute reconciler both do `INSERT ... ON DUPLICATE KEY UPDATE` assuming
     * the unique key is (msgid, groupid), and deliberately never update groupid on
     * conflict.
     *
     * But the table was created with a single-column `UNIQUE (msgid)`. So the second
     * and subsequent groups for a message collapsed onto the first-inserted row: a
     * rippled post ended up indexed on only ONE (non-deterministic) group and was
     * invisible in browse on all the others - including its origin group. Reported on
     * Discourse 9954 ("Post disappeared after I approved it"): an approved Wanted that
     * could not be found because its single spatial row landed on a rippled-into group,
     * not the poster's / moderator's own group.
     *
     * Fix: make the unique key (msgid, groupid). The composite still serves the
     * WHERE msgid = ? lookups (msgid leads). Then backfill the missing per-group rows
     * for messages that are currently indexed, so the browse gap clears without waiting
     * for each post to be re-touched by the reconciler.
     */
    private function indexExists(string $table, string $index): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    public function up(): void
    {
        if (!Schema::hasTable('messages_spatial')) {
            return;
        }

        // 1) Add the correct composite unique. Safe: with UNIQUE(msgid) there is at
        //    most one row per msgid today, so every (msgid, groupid) pair is unique.
        if (!$this->indexExists('messages_spatial', 'msgid_groupid')) {
            DB::statement('ALTER TABLE `messages_spatial` ADD UNIQUE `msgid_groupid` (`msgid`, `groupid`)');
        }

        // 2) Drop the single-column unique that collapsed the per-group rows. The
        //    composite above keeps msgid as a leading indexed column for lookups.
        if ($this->indexExists('messages_spatial', 'msgid')) {
            DB::statement('ALTER TABLE `messages_spatial` DROP INDEX `msgid`');
        }

        // 3) Backfill the per-group rows that were previously lost, but only for
        //    messages already present in the index (bounded to the active browse
        //    window). INSERT IGNORE relies on the new (msgid, groupid) unique to skip
        //    the rows that already exist. Mirrors addApprovedMessageToSpatialIndex:
        //    Approved, not deleted, has a location, no outcome.
        DB::statement(
            'INSERT IGNORE INTO messages_spatial (msgid, point, groupid, msgtype, arrival) '.
            'SELECT m.id, '.
            "       ST_GeomFromText(CONCAT('POINT(', m.lng, ' ', m.lat, ')'), 3857), ".
            '       mg.groupid, m.type, '.
            "       DATE_FORMAT(mg.arrival, '%Y-%m-%d %H:%i:%s') ".
            'FROM (SELECT DISTINCT msgid FROM messages_spatial) s '.
            'INNER JOIN messages_groups mg ON mg.msgid = s.msgid '.
            "    AND mg.collection = 'Approved' AND mg.deleted = 0 ".
            'INNER JOIN messages m ON m.id = s.msgid '.
            '    AND m.deleted IS NULL AND m.lat IS NOT NULL AND m.lng IS NOT NULL '.
            'LEFT JOIN messages_outcomes mo ON mo.msgid = s.msgid '.
            'WHERE mo.id IS NULL'
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('messages_spatial')) {
            return;
        }

        // Restoring the single-column unique requires one row per msgid again, so
        // collapse duplicates (keep the lowest id) before re-adding it.
        if (!$this->indexExists('messages_spatial', 'msgid')) {
            DB::statement(
                'DELETE s1 FROM messages_spatial s1 '.
                'INNER JOIN messages_spatial s2 ON s1.msgid = s2.msgid AND s1.id > s2.id'
            );
            DB::statement('ALTER TABLE `messages_spatial` ADD UNIQUE `msgid` (`msgid`)');
        }

        if ($this->indexExists('messages_spatial', 'msgid_groupid')) {
            DB::statement('ALTER TABLE `messages_spatial` DROP INDEX `msgid_groupid`');
        }
    }
};
