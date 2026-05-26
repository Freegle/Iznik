<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('microactions')) {
            return;
        }

        // Part 1 — INSTANT in MySQL 8.0+: append the EEELabel enum value
        // and add four new nullable columns at the end of the table.
        // Both operations skip the row rewrite that ENUM-mid-list edits force.
        if (!Schema::hasColumn('microactions', 'eee_attachment_id')) {
            DB::statement("
                ALTER TABLE microactions
                  ADD COLUMN eee_attachment_id BIGINT UNSIGNED NULL DEFAULT NULL,
                  ADD COLUMN eee_condition     VARCHAR(32) NULL DEFAULT NULL,
                  ADD COLUMN eee_weight        VARCHAR(32) NULL DEFAULT NULL,
                  ADD COLUMN eee_size          VARCHAR(32) NULL DEFAULT NULL,
                  MODIFY COLUMN actiontype ENUM(
                    'CheckMessage','SearchTerm','Items','FacebookShare','PhotoRotate',
                    'ItemSize','ItemWeight','Survey','Survey2','Invite','AIImageReview',
                    'EEELabel'
                  ),
                  ALGORITHM=INSTANT
            ");
        }

        // Part 2 — INPLACE + LOCK=NONE (still online, no table lock).
        // Index/unique-key adds cannot be INSTANT. Uses eee_attachment_id, not
        // msgid, so it does not clash with the existing (userid,msgid) unique
        // key used by CheckMessage.
        $indexes = DB::select("SHOW INDEX FROM microactions WHERE Key_name IN ('idx_eee_attachment_id','userid_7')");
        if (empty($indexes)) {
            DB::statement("
                ALTER TABLE microactions
                  ADD INDEX idx_eee_attachment_id (eee_attachment_id),
                  ADD UNIQUE KEY userid_7 (userid, eee_attachment_id),
                  ALGORITHM=INPLACE, LOCK=NONE
            ");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('microactions')) {
            return;
        }

        $indexes = DB::select("SHOW INDEX FROM microactions WHERE Key_name IN ('idx_eee_attachment_id','userid_7')");
        if (!empty($indexes)) {
            DB::statement("ALTER TABLE microactions DROP INDEX userid_7, DROP INDEX idx_eee_attachment_id");
        }

        if (Schema::hasColumn('microactions', 'eee_attachment_id')) {
            DB::statement("
                ALTER TABLE microactions
                  DROP COLUMN eee_attachment_id,
                  DROP COLUMN eee_condition,
                  DROP COLUMN eee_weight,
                  DROP COLUMN eee_size,
                  MODIFY COLUMN actiontype ENUM(
                    'CheckMessage','SearchTerm','Items','FacebookShare','PhotoRotate',
                    'ItemSize','ItemWeight','Survey','Survey2','Invite','AIImageReview'
                  )
            ");
        }
    }
};
