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
        Schema::table('logs', function (Blueprint $table) {
            // Store the message subject at the time each log event occurs so that
            // ModTools can show the historical subject rather than the current (possibly
            // edited) one.  Nullable because existing rows pre-date this column, and
            // because not every log type has an associated message subject.
            $table->string('msgsubject', 80)->nullable()->after('msgid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logs', function (Blueprint $table) {
            $table->dropColumn('msgsubject');
        });
    }
};
