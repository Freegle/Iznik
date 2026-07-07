<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add AIImageReview to the actiontype enum on microactions.
        // This is a table rebuild on MySQL/Percona — run via pt-online-schema-change on production.
        if (Schema::hasTable('microactions')) {
            // Extend the enum to include AIImageReview.
            DB::statement("ALTER TABLE microactions MODIFY COLUMN actiontype ENUM('CheckMessage','SearchTerm','Items','FacebookShare','PhotoRotate','ItemSize','ItemWeight','Survey','Survey2','Invite','AIImageReview')");

            // Add aiimageid column (appended to end to enable ALGORITHM=INSTANT on MySQL 8.0).
            if (!Schema::hasColumn('microactions', 'aiimageid')) {
                DB::statement("ALTER TABLE microactions ADD COLUMN aiimageid BIGINT UNSIGNED NULL DEFAULT NULL");
                DB::statement("ALTER TABLE microactions ADD INDEX idx_aiimageid (aiimageid)");
                DB::statement("ALTER TABLE microactions ADD UNIQUE KEY userid_6 (userid, aiimageid)");
            }

            // Add containspeople column.
            if (!Schema::hasColumn('microactions', 'containspeople')) {
                DB::statement("ALTER TABLE microactions ADD COLUMN containspeople TINYINT(1) NULL DEFAULT NULL");
            }
        }

        // Add usage_count to ai_images.
        if (Schema::hasTable('ai_images') && !Schema::hasColumn('ai_images', 'usage_count')) {
            DB::statement("ALTER TABLE ai_images ADD COLUMN usage_count INT UNSIGNED NOT NULL DEFAULT 0");
            DB::statement("ALTER TABLE ai_images ADD INDEX idx_usage_count (usage_count)");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('microactions')) {
            if (Schema::hasColumn('microactions', 'containspeople')) {
                DB::statement("ALTER TABLE microactions DROP COLUMN containspeople");
            }

            if (Schema::hasColumn('microactions', 'aiimageid')) {
                DB::statement("ALTER TABLE microactions DROP INDEX userid_6");
                DB::statement("ALTER TABLE microactions DROP INDEX idx_aiimageid");
                DB::statement("ALTER TABLE microactions DROP COLUMN aiimageid");
            }

            DB::statement("ALTER TABLE microactions MODIFY COLUMN actiontype ENUM('CheckMessage','SearchTerm','Items','FacebookShare','PhotoRotate','ItemSize','ItemWeight','Survey','Survey2','Invite')");
        }

        if (Schema::hasTable('ai_images') && Schema::hasColumn('ai_images', 'usage_count')) {
            DB::statement("ALTER TABLE ai_images DROP INDEX idx_usage_count");
            DB::statement("ALTER TABLE ai_images DROP COLUMN usage_count");
        }
    }
};
