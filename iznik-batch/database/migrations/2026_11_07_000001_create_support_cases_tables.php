<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support cases: one row per chat a moderator refers to Support, with the AI first pass that runs
 * before a person sees it, and the thread of replies between Support and the referring moderator.
 *
 * - support_cases.brief is the first pass's result as JSON (the fixed-shape facts, the draft to the
 *   referring moderator, confidence, classification). Only Support and Admin may read it; the Go API
 *   leaves it out for the referring moderator.
 * - support_case_messages is the thread. Nothing in it goes to the member: Support replies to the
 *   referring moderator, who deals with the member.
 * - support_ai_runs.kind tells a first pass from a question a volunteer typed into the helper.
 *
 * Production SQL: 2026_11_07_000001_create_support_cases_tables_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('support_cases')) {
            Schema::create('support_cases', function (Blueprint $table) {
                $table->bigIncrements('id');
                // The referred chat.
                $table->unsignedBigInteger('chatid')->nullable();
                // The member the case is about. NULL for a chat with no member, such as Mod2Mod.
                $table->unsignedBigInteger('userid')->nullable();
                // The moderator who referred it.
                $table->unsignedBigInteger('referrerid')->nullable();
                $table->enum('source', ['Referral', 'Mail'])->default('Referral');
                // What the referrer said they need help with, if anything.
                $table->text('reason')->nullable();
                $table->enum('status', ['Open', 'Handled'])->default('Open');
                $table->enum('firstpass_status', ['Pending', 'Running', 'Done', 'Failed', 'Skipped'])->default('Pending');
                // Why the first pass did not run or did not finish, in plain words.
                $table->string('firstpass_error', 255)->nullable();
                $table->unsignedTinyInteger('firstpass_attempts')->default(0);
                $table->unsignedBigInteger('firstpass_runid')->nullable();
                $table->longText('brief')->nullable();
                // "<chatid>-<referrerid>" while the case is Open, NULL once Handled. Set by the code, not
                // generated: MySQL refuses a stored generated column over a SET NULL foreign key. The
                // unique index makes a double-click, or two requests at once, find the same case.
                $table->string('open_key', 64)->nullable();
                // When the referral mail to Support was spooled. Every path that sends it sets this with
                // "WHERE mailed_at IS NULL" and spools only if that updated the row, so it goes once.
                $table->timestamp('mailed_at')->nullable();
                $table->unsignedBigInteger('handledby')->nullable();
                $table->timestamp('handled_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique('open_key');
                $table->index(['status', 'id']);
                $table->index(['chatid', 'status']);
                $table->index('referrerid');
                $table->index(['firstpass_status', 'created_at']);
                $table->foreign('chatid')->references('id')->on('chat_rooms')->onDelete('set null');
                $table->foreign('userid')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('referrerid')->references('id')->on('users')->onDelete('set null');
                $table->foreign('firstpass_runid')->references('id')->on('support_ai_runs')->onDelete('set null');
                $table->foreign('handledby')->references('id')->on('users')->onDelete('set null');
            });
        }

        if (!Schema::hasTable('support_case_messages')) {
            Schema::create('support_case_messages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('caseid');
                // The author.
                $table->unsignedBigInteger('userid')->nullable();
                // Support writes to the referring moderator; the referrer writes to Support.
                $table->enum('role', ['Support', 'Referrer']);
                $table->text('body');
                // Whether Support sent the first pass's draft as written, edited it, or wrote their own.
                $table->enum('draftused', ['No', 'Unchanged', 'Edited'])->default('No');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['caseid', 'id']);
                $table->foreign('caseid')->references('id')->on('support_cases')->onDelete('cascade');
                $table->foreign('userid')->references('id')->on('users')->onDelete('set null');
            });
        }

        if (!Schema::hasColumn('support_ai_runs', 'kind')) {
            DB::statement("ALTER TABLE support_ai_runs ADD COLUMN kind ENUM('Interactive','FirstPass') NOT NULL DEFAULT 'Interactive' AFTER modid, ALGORITHM=INSTANT");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_case_messages');
        Schema::dropIfExists('support_cases');
        if (Schema::hasColumn('support_ai_runs', 'kind')) {
            DB::statement('ALTER TABLE support_ai_runs DROP COLUMN kind');
        }
    }
};
