<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * logs_jobs.placement / source: which slot a WhatJobs ad click came from, so click-through and
 * revenue can be measured PER PLACEMENT (mobile/desktop sticky footer, left/right sidebar, jobs
 * page, email redirect, and the more-jobs modal) instead of as a single global click count. Until
 * now every web click was logged with no slot identifier at all (the frontend hardcoded a single
 * 'daslot' context), so "which location is most productive" was unanswerable.
 *
 * Both columns are nullable: pre-existing rows and any caller that doesn't send a value stay NULL,
 * so this is a non-breaking, fully reversible change. Written by the Go apiv2 RecordJobClick handler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logs_jobs', function (Blueprint $t) {
            if (!Schema::hasColumn('logs_jobs', 'placement')) {
                $t->string('placement', 32)->nullable()
                    ->comment("Slot the click came from: sticky_footer_mobile/desktop, sidebar_left/right, jobs_page, email_redirect, modal_more_jobs");
                $t->index('placement', 'logs_jobs_placement_idx');
            }
            if (!Schema::hasColumn('logs_jobs', 'source')) {
                $t->string('source', 16)->nullable()
                    ->comment("'website' or 'email'; NULL for legacy rows");
            }
        });
    }

    public function down(): void
    {
        Schema::table('logs_jobs', function (Blueprint $t) {
            if (Schema::hasColumn('logs_jobs', 'placement')) {
                $t->dropIndex('logs_jobs_placement_idx');
                $t->dropColumn('placement');
            }
            if (Schema::hasColumn('logs_jobs', 'source')) {
                $t->dropColumn('source');
            }
        });
    }
};
