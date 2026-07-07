<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('jobs', 'canonical_title')) {
            return;
        }

        Schema::table('jobs', function (Blueprint $table) {
            $table->string('canonical_title', 255)->nullable()->after('visible');
            $table->index('canonical_title');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex(['canonical_title']);
            $table->dropColumn('canonical_title');
        });
    }
};
