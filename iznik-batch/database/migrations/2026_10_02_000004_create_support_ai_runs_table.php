<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per question put to the AI Support Helper: what was asked, what it
 * did, what it answered, what it used up, and how well the volunteer thought it
 * did. Read most recent first in ModTools SysAdmin, so cases that went badly can
 * be found and the prompt or tools improved.
 *
 * The helper records the run through the Go API, because its own database
 * connection is a read-only grant.
 *
 * quota_* are the Claude subscription's five-hour and seven-day utilisation
 * (0-100) just before and just after the run. Anything else using the same
 * subscription at the time also moves them, so the difference is an upper
 * bound on what this run used, not an exact figure. NULL means the helper is
 * not on a subscription or the usage endpoint did not answer.
 *
 * Production SQL: 2026_10_02_000004_create_support_ai_runs_table_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_ai_runs')) {
            return;
        }

        Schema::create('support_ai_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            // The Support or Admin volunteer who asked.
            $table->unsignedBigInteger('modid')->nullable();
            // The member being investigated, if one was selected.
            $table->unsignedBigInteger('userid')->nullable();
            // Claude session id; follow-up questions in one investigation share it.
            $table->string('sessionid', 64)->nullable();
            $table->mediumText('query');
            $table->mediumText('analysis')->nullable();
            // JSON list of what the agent did between question and answer: its
            // interim text, each tool call with its input, and a capped copy of
            // each tool result.
            $table->longText('transcript')->nullable();
            $table->enum('status', ['Success', 'Error'])->default('Success');
            $table->text('error')->nullable();
            $table->string('driver', 16)->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_creation_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->decimal('quota_5h_before', 5, 2)->nullable();
            $table->decimal('quota_5h_after', 5, 2)->nullable();
            $table->decimal('quota_7d_before', 5, 2)->nullable();
            $table->decimal('quota_7d_after', 5, 2)->nullable();
            // 1 = thumbs up, -1 = thumbs down, NULL = not rated.
            $table->tinyInteger('rating')->nullable();
            $table->text('rating_comment')->nullable();
            $table->unsignedBigInteger('ratedby')->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('rating');
            $table->index('sessionid');
            $table->foreign('modid')->references('id')->on('users')->onDelete('set null');
            $table->foreign('userid')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('ratedby')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ai_runs');
    }
};
