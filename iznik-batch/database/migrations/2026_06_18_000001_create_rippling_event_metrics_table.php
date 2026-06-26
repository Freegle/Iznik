<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Live per-event counters for the rippling-out rollout (design §15/§16 — "instrument from
 * day one"). One row per (day, event); incremented in place by an INSERT ... ON DUPLICATE
 * KEY UPDATE upsert at each event point (reply-blocked-by-reach #2, held-reply state
 * transitions #3, secondary-group rejection #6, immediate mail on expansion #0, rippled-in
 * group #6). Distinct from ripple_algorithm_metrics (the offline curve-tuning simulator's
 * weekly rollup). Surfaced read-only in sysadmin.
 *
 * Created with IF NOT EXISTS so it can ship in whichever rippling PR merges first.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE TABLE IF NOT EXISTS rippling_event_metrics (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                day DATE NOT NULL,
                event VARCHAR(32) NOT NULL,
                count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY ripple_event_day_event (day, event)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS rippling_event_metrics');
    }
};
