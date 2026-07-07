<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * logs(user, groupid, type, subtype) composite index - makes the Rippling Out "did this user
 * leave this group?" guard a point-lookup (PR #855). The ripple expander must not re-join (or
 * re-ripple a post into) a group the poster has actively left, which it detects via a
 * Group/Left log row; without this index that NOT EXISTS sub-select would scan.
 *
 * Index-only, high-cardinality on (user, groupid). On the large production logs table this is an
 * online ADD INDEX (ALGORITHM=INPLACE, LOCK=NONE in MySQL 8). Idempotent: guarded on
 * information_schema so a re-run is a no-op.
 */
return new class extends Migration {
    public function up(): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'logs' AND index_name = 'ripple_leave_guard'"
        );
        if ((int) ($exists->n ?? 0) === 0) {
            DB::statement('ALTER TABLE logs ADD INDEX ripple_leave_guard (user, groupid, type, subtype)');
        }
    }

    public function down(): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'logs' AND index_name = 'ripple_leave_guard'"
        );
        if ((int) ($exists->n ?? 0) > 0) {
            DB::statement('ALTER TABLE logs DROP INDEX ripple_leave_guard');
        }
    }
};
