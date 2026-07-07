<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalise the email_tracking sent_at index name to `sent_at`.
     *
     * The create migration (2025_12_20_000000) guards its whole table+index
     * creation behind !hasTable('email_tracking'). Installs whose email_tracking
     * pre-dated the index being named `sent_at` — CircleCI and local dev databases
     * built from the original migration — therefore kept the Laravel-default name
     * `email_tracking_sent_at_index`, and the guard never brings them into line.
     *
     * The Go email-stats handlers use `FORCE INDEX (sent_at)`, which hard-errors
     * (MySQL 1176 "Key 'sent_at' doesn't exist") unless that exact name exists, so
     * on those installs the stats endpoints silently return no rows.
     *
     * Live's email_tracking pre-dates these migrations and already has a `sent_at`
     * index, so this is a no-op on Live and on any freshly-migrated install — it
     * only renames the index on the CircleCI / dev databases that drifted.
     */
    public function up(): void
    {
        if (!Schema::hasTable('email_tracking')) {
            return;
        }

        $indexes = collect(DB::select('SHOW INDEX FROM email_tracking'))
            ->pluck('Key_name')
            ->unique();

        // Already correct (Live, fresh installs).
        if ($indexes->contains('sent_at')) {
            return;
        }

        if ($indexes->contains('email_tracking_sent_at_index')) {
            DB::statement('ALTER TABLE email_tracking RENAME INDEX email_tracking_sent_at_index TO sent_at');
        } else {
            DB::statement('CREATE INDEX sent_at ON email_tracking (sent_at)');
        }
    }

    public function down(): void
    {
        // One-way normalisation to match Live; not reversed.
    }
};
