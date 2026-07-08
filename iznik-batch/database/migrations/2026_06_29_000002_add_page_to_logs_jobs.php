<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * logs_jobs.page: the Nuxt route NAME of the page a WhatJobs ad click came from (e.g. 'jobs',
 * 'browse-term', 'index', 'message-id'), orthogonal to `placement` (which slot) and `source`
 * (website/email). Placement alone can't answer "which page earns the most" because the same
 * slot (sticky footer, sidebar) appears on every page; this column adds the page axis so
 * click-through and revenue can be grouped by page as well as by slot.
 *
 * Separate migration (not appended to 2026_06_29_000001) because that one is already applied,
 * and Laravel never re-runs a recorded migration.
 *
 * Route NAME, not path: Nuxt derives it from the file path with dynamic segments collapsed
 * (pages/explore/[[id]].vue -> 'explore-id'), so every /explore/* URL is one token. The longest
 * live route name is 'one-click-unsubscribe-uid-key' (29 chars); VARCHAR(64) gives a >2x margin.
 * Nullable so legacy rows and the email-redirect path (which has no meaningful page) stay NULL.
 * No index: ~55-90 distinct route names is the same low-cardinality profile as `source` (also
 * un-indexed); analytics filter first on the indexed `placement` or a date range, then GROUP BY page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logs_jobs', function (Blueprint $t) {
            if (!Schema::hasColumn('logs_jobs', 'page')) {
                $t->string('page', 64)->nullable()
                    ->comment("Nuxt route name of the page the click came from, e.g. jobs/browse-term/index/message-id; NULL for email-redirect and legacy rows");
            }
        });
    }

    public function down(): void
    {
        Schema::table('logs_jobs', function (Blueprint $t) {
            if (Schema::hasColumn('logs_jobs', 'page')) {
                $t->dropColumn('page');
            }
        });
    }
};
