<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('admins', 'lastchaseup')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->timestamp('lastchaseup')->nullable()->after('heldat');
            });
        }
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('lastchaseup');
        });
    }
};
