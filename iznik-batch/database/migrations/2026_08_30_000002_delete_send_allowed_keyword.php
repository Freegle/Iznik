<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delete the 'send' allowed keyword: it silently disabled 'send the money'.
 *
 * Found by running 2026_08_30_000001's verification query (b) against
 * production: with the whitelist rows restored, exactly one allowed phrase
 * still sat as a whole word inside a keyword that flags — 'send', a global
 * 'allowed' row bulk-migrated from worrywords (id 485, type 'Allowed'),
 * word-boundary-matching inside the scam keyword 'send the money'. Allowed
 * phrases are cut out of the text before scanning, so the entry didn't
 * protect anything: it deleted the word 'send' from every message and made
 * 'send the money' unmatchable.
 *
 * Like 'grass' in the previous migration, the entry is a legacy of the old
 * substring engine and word-boundary matching leaves it with no protective
 * job at all — nothing can fire inside 'send' — so it is deleted rather than
 * recategorised. Applied to production by hand on 2026-08-30 (single-row
 * DELETE, id 368); this migration converges dev and the test schemas, and is
 * idempotent for anyone who already has it gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('concern_keywords')) {
            return;
        }

        DB::delete("DELETE FROM concern_keywords WHERE LOWER(keyword) = 'send' AND category = 'allowed' AND scope = 'global'");
    }

    public function down(): void
    {
        // Deliberately empty: restoring the row would re-disable 'send the
        // money', and the row had no protective function to restore.
    }
};
