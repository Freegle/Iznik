<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the legacy `logos` table.
     *
     * The `logos` table backed the special-occasion "doodle" logo variants
     * feature (a random active logo served on a matching calendar day, like a
     * Google Doodle). That feature has been removed from both the client and
     * the V2 API, so the table is no longer read or written anywhere.
     *
     * On production run the paired 2026_07_09_000001_drop_logos_table.sql
     * instead (it is idempotent and does not need the migration runner).
     */
    public function up(): void
    {
        Schema::dropIfExists('logos');
    }

    /**
     * Recreate the table exactly as it was defined in
     * 2025_12_10_094529_create_logos_table.php.
     */
    public function down(): void
    {
        if (Schema::hasTable('logos')) {
            return;
        }

        Schema::create('logos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('path');
            $table->string('date', 5)->index('date');
            $table->string('reason', 80)->nullable();
            $table->boolean('active')->default(true);
        });
    }
};
