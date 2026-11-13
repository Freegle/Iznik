<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Support cases are gone: a referral to Support is now one queued task and one mail, with nothing
 * stored. This drops the two case tables with whatever they hold.
 *
 * support_ai_runs and its kind column stay: the AI helper's run log uses them, and a first pass is
 * still recorded there.
 *
 * Production SQL: 2026_11_13_000001_drop_support_cases_tables_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('support_case_messages');
        Schema::dropIfExists('support_cases');
    }

    public function down(): void
    {
        // The tables are not brought back: nothing reads or writes them.
    }
};
