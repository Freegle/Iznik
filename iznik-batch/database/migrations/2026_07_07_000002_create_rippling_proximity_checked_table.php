<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_proximity_checked — checked-once-forever negative-memoization marker for
 * ripple:proximity-notes (Phase 0 of plans/routing-performance-step-change.md). A row means this
 * (msgid, groupid) rippled-in copy got a DEFINITIVE proximity answer (note written, not quicker,
 * or unreachable within budget) and is never re-queried. Without it, "no note needed" rows were
 * recomputed on every 5-minute run for the whole 8-day candidate window — up to ~12 CPU-hours/day
 * of routing work re-deriving already-known answers (2026-07-06 Sentry storm, group 21521).
 *
 * Deliberately a separate marker table rather than nullable p/q columns on rippling_proximity:
 * iznik-server-go reads rippling_proximity and scans p/q as non-null strings. Failed calls
 * (timeout, non-2xx, routing server mid-restart) are NOT marked, so those rows retry next run.
 *
 * The command purges rows older than 14 days: candidates require mg.arrival within 8 days, so a
 * marker past that window can never match a candidate again — "checked once, forever" is
 * guaranteed by the arrival window, not by keeping the marker around.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('rippling_proximity_checked')) {
            return;
        }

        Schema::create('rippling_proximity_checked', function (Blueprint $t) {
            $t->unsignedBigInteger('msgid');
            $t->unsignedBigInteger('groupid');
            $t->timestamp('checked_at')->useCurrent();
            $t->primary(['msgid', 'groupid']);
            $t->index('checked_at');
        });

        DB::statement(
            'ALTER TABLE rippling_proximity_checked
                ADD CONSTRAINT rippling_proximity_checked_msgid_foreign
                FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE'
        );
        DB::statement(
            'ALTER TABLE rippling_proximity_checked
                ADD CONSTRAINT rippling_proximity_checked_groupid_foreign
                FOREIGN KEY (groupid) REFERENCES `groups` (id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_proximity_checked');
    }
};
