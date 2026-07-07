<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('housekeeper_tasks', 'start_date')) {
            return;
        }

        Schema::table('housekeeper_tasks', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('interval_hours')
                ->comment('When the recurring cycle starts, e.g. 2026-07-01 for yearly July tasks');
        });
    }

    public function down(): void
    {
        Schema::table('housekeeper_tasks', function (Blueprint $table) {
            $table->dropColumn('start_date');
        });
    }
};
