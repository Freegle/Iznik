<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The UX ideas backlog: one row per idea the idea generator proposed for a funnel, with the
 * experiment it became. docs/developers/reference/ux-ideas.md.
 *
 * The idea points at its experiment and at the member problem it answers, and neither of those
 * tables points back. Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ux_ideas')) {
            Schema::create('ux_ideas', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('flow', 32);
                $t->string('title', 160);
                $t->text('rationale');
                $t->text('expected');
                // category, expected_points, confidence, locality, risk, files, like_tried, change and
                // problem (the generator's own problem: where, source, evidence).
                $t->json('evidence')->nullable();
                $t->decimal('score', 6, 2)->default(0);
                // 'open', 'scheduled', 'running', 'done' or 'rejected'.
                $t->string('status', 16)->default('open');
                // 'fix' (a defect with a right answer, shipped as an ordinary pull request) or
                // 'experiment' (needs a test).
                $t->string('type', 16)->default('experiment');
                // Always 'ai': member suggestions are problems, not ideas.
                $t->string('source', 16)->default('ai');
                // The member problem this idea answers (ux_problems.id), when the generator cited one.
                $t->unsignedBigInteger('problem_id')->nullable();
                // The generator's own title for the problem, kept when no member problem matches.
                $t->string('problem_title', 160)->nullable();
                $t->unsignedBigInteger('experiment_id')->nullable();
                // How many tests of this idea ended inconclusive. After the configured number it is done.
                $t->unsignedInteger('attempts')->default(0);
                $t->timestamps();
                $t->index(['flow', 'status'], 'ux_ideas_flow_status');
                $t->index('problem_id', 'ux_ideas_problem');
                $t->index('experiment_id', 'ux_ideas_experiment');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ux_ideas');
    }
};
