<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The UX experiment engine: one row per experiment, and one row per hourly read of how it is going.
 * docs/developers/reference/ux-experiments.md.
 *
 * Everything an experiment needs is a row here, so a new experiment never needs a schema change.
 * Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ux_experiments')) {
            Schema::create('ux_experiments', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('flow', 32);
                // The switch key the client reads: useExperiment('<name>').
                $t->string('name', 64)->unique('ux_experiments_name');
                $t->string('title', 160);
                $t->text('hypothesis');
                $t->text('change_description');
                $t->text('expected');
                $t->json('arms');
                $t->string('status', 16)->default('draft');
                $t->timestamp('started_at')->nullable();
                $t->timestamp('ends_at')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->text('decision')->nullable();
                $t->json('result')->nullable();
                $t->string('pr_url', 255)->nullable();
                $t->string('cleanup_pr_url', 255)->nullable();
                // What the change can touch (the flows its guardrails read), the expected effect and the
                // power computed at start. See UxExperimentService::start().
                $t->json('design')->nullable();
                $t->timestamps();
                $t->index(['flow', 'status'], 'ux_experiments_flow_status');
            });
        }

        if (! Schema::hasTable('ux_experiment_reads')) {
            Schema::create('ux_experiment_reads', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('experiment_id');
                $t->timestamp('read_at');
                $t->decimal('hours', 6, 2);
                $t->json('metrics');
                $t->text('summary');
                $t->index(['experiment_id', 'read_at'], 'ux_experiment_reads_lookup');
            });
        }
    }

    public function down(): void
    {
        foreach (['ux_experiment_reads', 'ux_experiments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
