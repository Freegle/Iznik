<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * lockdowns.notice holds the member notice text itself, written by the presser, rather than
 * a key for one of two fixed wordings. NULL means no notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('lockdowns', 'notice')) {
            DB::statement('ALTER TABLE lockdowns MODIFY COLUMN notice TEXT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lockdowns', 'notice')) {
            DB::statement('ALTER TABLE lockdowns MODIFY COLUMN notice VARCHAR(20) NULL');
        }
    }
};
