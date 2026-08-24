<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('newsfeed', 'modified')) {
            return;
        }

        Schema::table('newsfeed', function (Blueprint $table) {
            $table->timestamp('modified')
                ->nullable()
                ->useCurrentOnUpdate()
                ->default(null)
                ->after('added');

            $table->index('modified');
        });

        DB::statement('UPDATE newsfeed SET modified = added WHERE modified IS NULL');
    }

    public function down(): void
    {
        Schema::table('newsfeed', function (Blueprint $table) {
            $table->dropIndex(['modified']);
            $table->dropColumn('modified');
        });
    }
};
