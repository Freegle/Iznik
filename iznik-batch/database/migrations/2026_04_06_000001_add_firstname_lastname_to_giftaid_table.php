<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('giftaid', 'firstname')) {
            return;
        }

        Schema::table('giftaid', function (Blueprint $table) {
            $table->string('firstname')->nullable()->after('fullname');
            $table->string('lastname')->nullable()->after('firstname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('giftaid', function (Blueprint $table) {
            $table->dropColumn(['firstname', 'lastname']);
        });
    }
};
