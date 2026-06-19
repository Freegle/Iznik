<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make messages_spatial per-group.
 *
 * messages_spatial backs the public browse/map and search, which filter by
 * `groupid`. The table previously had a single-column UNIQUE(msgid), so a
 * message cross-posted to several groups got exactly one spatial row carrying
 * one groupid — making it visible in browse/search on only one of its groups,
 * and causing the reconciler to flip-flop the stored groupid between runs.
 *
 * Switching the unique key to (msgid, groupid) lets the writers maintain one
 * row per group the message is approved on. The location (point) is identical
 * across those rows (a message has a single location), so the external spatial
 * server still indexes one point per msgid (it dedups on load).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('messages_spatial')) {
            return;
        }

        // Already migrated → no-op (production may have been changed manually).
        if ($this->indexExists('messages_spatial_msgid_groupid_unique')) {
            return;
        }

        // The msgid FK pins the single-column unique index we need to replace —
        // drop it first, then restore it against the new composite index.
        if ($this->foreignKeyExists('messages_spatial_msgid_foreign')) {
            DB::statement('ALTER TABLE messages_spatial DROP FOREIGN KEY messages_spatial_msgid_foreign');
        }

        // Drop whatever UNIQUE index is solely on msgid (the create migration
        // named it `msgid`, but detect by shape to be name-agnostic).
        foreach ($this->singleColumnUniqueIndexesOn('msgid') as $indexName) {
            DB::statement('ALTER TABLE messages_spatial DROP INDEX `' . $indexName . '`');
        }

        // One spatial row per (message, group).
        DB::statement('ALTER TABLE messages_spatial ADD UNIQUE INDEX messages_spatial_msgid_groupid_unique (msgid, groupid)');

        // Restore the msgid FK — now backed by the composite index (msgid leftmost).
        if (!$this->foreignKeyExists('messages_spatial_msgid_foreign')) {
            DB::statement('ALTER TABLE messages_spatial ADD CONSTRAINT messages_spatial_msgid_foreign FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE ON UPDATE NO ACTION');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('messages_spatial')) {
            return;
        }
        if (!$this->indexExists('messages_spatial_msgid_groupid_unique')) {
            return;
        }

        // Collapse to one row per msgid so the single-column unique can be restored.
        DB::statement('
            DELETE ms FROM messages_spatial ms
            INNER JOIN messages_spatial keep
              ON keep.msgid = ms.msgid AND keep.id < ms.id
        ');

        if ($this->foreignKeyExists('messages_spatial_msgid_foreign')) {
            DB::statement('ALTER TABLE messages_spatial DROP FOREIGN KEY messages_spatial_msgid_foreign');
        }
        DB::statement('ALTER TABLE messages_spatial DROP INDEX messages_spatial_msgid_groupid_unique');
        if (!$this->indexExists('msgid')) {
            DB::statement('ALTER TABLE messages_spatial ADD UNIQUE INDEX `msgid` (msgid)');
        }
        if (!$this->foreignKeyExists('messages_spatial_msgid_foreign')) {
            DB::statement('ALTER TABLE messages_spatial ADD CONSTRAINT messages_spatial_msgid_foreign FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE ON UPDATE NO ACTION');
        }
    }

    private function indexExists(string $index): bool
    {
        return collect(DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            ['messages_spatial', $index]
        ))->isNotEmpty();
    }

    private function foreignKeyExists(string $constraint): bool
    {
        return collect(DB::select(
            "SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ? AND constraint_type = 'FOREIGN KEY' LIMIT 1",
            ['messages_spatial', $constraint]
        ))->isNotEmpty();
    }

    /**
     * @return array<int,string> names of UNIQUE indexes that consist solely of $column
     */
    private function singleColumnUniqueIndexesOn(string $column): array
    {
        // Alias the column explicitly: information_schema column names come back
        // upper-cased on some MySQL/Percona configs, so we can't rely on $r->index_name.
        $rows = DB::select(
            "SELECT index_name AS idxname FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'messages_spatial' AND non_unique = 0
             GROUP BY index_name
             HAVING COUNT(*) = 1 AND MAX(column_name) = ?",
            [$column]
        );

        return array_map(fn ($r) => $r->idxname, $rows);
    }
};
