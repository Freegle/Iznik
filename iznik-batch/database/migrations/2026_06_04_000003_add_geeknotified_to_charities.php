<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track which charity-partner signups have been notified to the geeks team.
 *
 * The Go API records signups in `charities` (status Pending) and emails
 * partnerships@, but nobody is watching them — the one live row has sat Pending
 * since April. charity:notify-signups emails geeks@ about new entries; this flag
 * records who has been notified so each signup is reported exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charities', function (Blueprint $table) {
            $table->boolean('geeknotified')->default(false)->after('status');
            $table->index('geeknotified', 'charities_geeknotified_idx');
        });
    }

    public function down(): void
    {
        Schema::table('charities', function (Blueprint $table) {
            $table->dropIndex('charities_geeknotified_idx');
            $table->dropColumn('geeknotified');
        });
    }
};
