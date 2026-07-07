<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('messages_spatial', 'modified')) {
            // Already applied to production manually — just ensure the index exists.
            if (!collect(\DB::select("SHOW INDEX FROM messages_spatial WHERE Key_name = 'messages_spatial_modified_index'"))->isNotEmpty()) {
                Schema::table('messages_spatial', function (Blueprint $table) {
                    $table->index('modified');
                });
            }
            return;
        }

        Schema::table('messages_spatial', function (Blueprint $table) {
            $table->timestamp('modified')
                ->nullable()
                ->useCurrentOnUpdate()
                ->default(null)
                ->after('arrival');

            $table->index('modified');
        });

        DB::statement('UPDATE messages_spatial SET modified = arrival WHERE modified IS NULL');
    }

    public function down(): void
    {
        Schema::table('messages_spatial', function (Blueprint $table) {
            $table->dropIndex(['modified']);
            $table->dropColumn('modified');
        });
    }
};
