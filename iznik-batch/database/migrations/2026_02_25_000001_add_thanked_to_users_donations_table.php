<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The thanked column exists in production (see schema.sql) but was missed
     * when the auto-generated migration was created.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users_donations', 'thanked')) {
            return;
        }

        Schema::table('users_donations', function (Blueprint $table) {
            $table->timestamp('thanked')->nullable()->after('TransactionType');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_donations', function (Blueprint $table) {
            $table->dropColumn('thanked');
        });
    }
};
