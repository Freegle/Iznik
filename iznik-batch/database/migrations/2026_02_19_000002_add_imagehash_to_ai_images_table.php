<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the imagehash column to ai_images.
 *
 * This column was missing from the original generated migration but exists
 * in the production schema. Used for deduplicating AI-generated images.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_images') && !Schema::hasColumn('ai_images', 'imagehash')) {
            Schema::table('ai_images', function (Blueprint $table) {
                $table->string('imagehash', 32)->nullable()->after('externaluid');
                $table->index('imagehash', 'idx_imagehash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_images') && Schema::hasColumn('ai_images', 'imagehash')) {
            Schema::table('ai_images', function (Blueprint $table) {
                $table->dropIndex('idx_imagehash');
                $table->dropColumn('imagehash');
            });
        }
    }
};
