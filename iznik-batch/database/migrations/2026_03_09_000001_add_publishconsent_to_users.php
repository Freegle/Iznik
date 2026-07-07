<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'publishconsent')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('publishconsent')->default(false)->after('marketingconsent')
                    ->comment('Can we republish posts to non-members');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('publishconsent');
        });
    }
};
