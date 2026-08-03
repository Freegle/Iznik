<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHY a chat message was dropped during processing.
 *
 * A message that fails processing is set processingsuccessful = 0, which permanently
 * excludes it from chat notifications - the recipient is never told it exists, and the
 * sender believes they were ignored. Until now nothing recorded the reason, so a
 * suppressed reply was indistinguishable from one that was never written, and support
 * had no way to answer "why didn't I see this reply?" except by guessing.
 *
 * The column is repurposed from facebookid rather than added. chat_messages is
 * ROW_FORMAT=COMPRESSED, so ADD COLUMN cannot use ALGORITHM=INSTANT and would rebuild
 * the table (36M rows on production, cluster-wide DDL). facebookid is dead: no code
 * writes it (the only writer was the retired V1 PHP API, and every caller passed NULL)
 * and production holds no non-NULL values. RENAME COLUMN is metadata-only even on a
 * compressed table, so this is what was applied to production (2026-07-30). The
 * renamed column keeps facebookid's varchar(255); fail reasons are short strings, so
 * the width difference is harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chat_messages', 'processingfailreason')) {
            return;
        }

        if (Schema::hasColumn('chat_messages', 'facebookid')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->renameColumn('facebookid', 'processingfailreason');
            });
        } else {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->string('processingfailreason', 32)->nullable()->after('processingsuccessful');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('chat_messages', 'processingfailreason')) {
            return;
        }

        if (Schema::hasColumn('chat_messages', 'facebookid')) {
            // The add path ran (facebookid still present): just drop the added column.
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('processingfailreason');
            });
        } else {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->renameColumn('processingfailreason', 'facebookid');
            });
        }
    }
};
