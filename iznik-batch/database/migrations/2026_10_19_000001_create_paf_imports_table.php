<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per request, from the SysAdmin "Addresses" tab, to download and apply a Royal Mail
 * address data (PAF) release. paf:import-run works through it and writes its progress here
 * for the page to show. docs/ops/runbooks/paf-address-data.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('paf_imports')) {
            Schema::create('paf_imports', function (Blueprint $t) {
                $t->bigIncrements('id');
                // queued, downloading, extracting, comparing, fetching, applying, done, failed.
                $t->string('status', 16)->default('queued');
                // An archived release such as Y26M08; null for the current one.
                $t->string('edition', 8)->nullable();
                // The unlock key from AFD's email. Cleared once the release is extracted.
                $t->string('unlock_key', 64)->nullable();
                // Percentage through the current step, and what that step is doing.
                $t->unsignedTinyInteger('progress')->default(0);
                $t->string('detail', 255)->nullable();
                $t->unsignedInteger('lines_in_release')->nullable();
                // Lines new or changed since the last release applied: the work to do.
                $t->unsignedInteger('lines_to_apply')->nullable();
                $t->unsignedInteger('lines_applied')->default(0);
                $t->unsignedInteger('inserted')->default(0);
                $t->unsignedInteger('updated')->default(0);
                // Lines whose postcode is not in locations yet; retried with the next release.
                $t->unsignedInteger('skipped')->default(0);
                $t->text('error')->nullable();
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
                $t->index('status', 'paf_imports_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('paf_imports');
    }
};
