<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The member surveys: the questions microvolunteers are asked, the answers they give, and the
 * problems those answers are coded into. docs/developers/reference/ux-member-surveys.md.
 *
 * Everything a question needs is a row here, so a new question never needs a schema change.
 * Nothing alters an existing table: microvolunteer answers go in ux_prompt_responses, not
 * microactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ux_prompts')) {
            Schema::create('ux_prompts', function (Blueprint $t) {
                $t->bigIncrements('id');
                // 'survey' (pick from the areas), 'awareness' (yes, no or not sure) or 'open' (write).
                $t->string('kind', 16);
                $t->string('flow', 32)->nullable();
                $t->string('question', 255);
                $t->text('body');
                $t->json('options');
                $t->json('images')->nullable();
                $t->string('status', 16)->default('open');
                $t->unsignedInteger('max_responses')->default(200);
                // show, worst_label, none_label, randomise, preset, placeholder, reveal, link, decoy,
                // followup_of, analysed_answers.
                $t->json('settings')->nullable();
                $t->timestamps();
                $t->index(['status', 'kind'], 'ux_prompts_status_kind');
            });
        }

        if (! Schema::hasTable('ux_problems')) {
            // What members tell us: problems, what they value, and the fixes they suggest. The coder
            // files each problem under one of a fixed list of group names.
            Schema::create('ux_problems', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('flow', 32)->nullable();
                // 'problem', 'value', 'suggestion' or 'other' (what fits no theme).
                $t->string('kind', 16)->default('problem');
                $t->string('group_name', 40)->nullable();
                $t->string('title', 160);
                $t->text('description')->nullable();
                $t->unsignedBigInteger('prompt_id')->nullable();
                // A suggestion points at the problem it answers.
                $t->unsignedBigInteger('parent_id')->nullable();
                // Distinct answers that raised it.
                $t->unsignedInteger('mentions')->default(0);
                // The share of times shown that a follow-up ranking picked it as the biggest problem.
                $t->decimal('weight', 6, 3)->nullable();
                $t->json('evidence')->nullable();
                $t->string('status', 16)->default('open');
                $t->timestamps();
                $t->index(['flow', 'status'], 'ux_problems_flow_status');
                $t->index('prompt_id', 'ux_problems_prompt');
                $t->index(['kind', 'group_name', 'status'], 'ux_problems_group');
            });
        }

        if (! Schema::hasTable('ux_prompt_responses')) {
            Schema::create('ux_prompt_responses', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('prompt_id');
                $t->unsignedBigInteger('userid');
                $t->json('answer');
                $t->text('comment')->nullable();
                $t->timestamp('timestamp')->useCurrent();
                $t->unique(['prompt_id', 'userid'], 'ux_prompt_responses_once');
                $t->index(['userid', 'timestamp'], 'ux_prompt_responses_user_time');
            });
        }
    }

    public function down(): void
    {
        foreach (['ux_problems', 'ux_prompt_responses', 'ux_prompts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
