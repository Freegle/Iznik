<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-message electricals classification, written by eee:classify-new.
 *
 * Until now these lived only in a dev-side SQLite file (EeeSqliteService), which was fine
 * for the model-comparison research but leaves nothing a public page can read. This is the
 * production home.
 *
 * is_eee is the Material Focus line - anything with a plug, battery or cable - with the
 * products the Environment Agency names hard-coded either way. See
 * plans/2026-08-25-eee-definition-decision.md. It is deliberately NOT a primary-function
 * test: a fish tank with a pump and a baby bouncer with a music player both count, and the
 * guidance names the first of those explicitly.
 *
 * is_eee is nullable and null means "the model observed nothing", which is not the same as
 * "observed nothing electrical". The stats page must exclude nulls from its denominators
 * rather than treating them as false.
 *
 * weight_bucket and size_bucket are stored but must not be published as precise figures:
 * measured against volunteer quorum they are 65% and 72% accurate. Published tonnage comes
 * from the `weights` reference table via the item/impact cascade instead, which is why there
 * is no weight_kg column here at all.
 *
 * The unique key carries model and prompt_version so the same message can be held under
 * several models when comparing them; the page filters to the production pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages_eee')) {
            return;
        }

        Schema::create('messages_eee', function (Blueprint $table) {
            $table->comment('Electricals classification per message, from eee:classify-new');
            $table->bigIncrements('id');

            $table->unsignedBigInteger('msgid');
            $table->unsignedBigInteger('attid')->nullable()
                ->comment('messages_attachments.id actually classified - the primary photo');

            // null = model observed nothing. Not the same as false.
            $table->boolean('is_eee')->nullable()
                ->comment('Material Focus line; null means unknown, never treat as false');
            $table->string('is_eee_reason', 32)->nullable()
                ->comment('named_eee | named_not_eee | primary | distinct_function | supplementary | no_electrical_components');
            $table->boolean('contains_eee_components')->nullable()
                ->comment('Physically contains electrical parts, independent of is_eee');

            $table->unsignedTinyInteger('weee_category')->nullable()
                ->comment('EA reporting category 1-15');

            // `condition` is reserved in MySQL, hence the prefix.
            $table->enum('item_condition', ['reusable', 'damaged', 'unsure'])->nullable()
                ->comment('93% accurate vs volunteer quorum - publishable');
            $table->enum('size_bucket', ['tiny', 'small', 'medium', 'large', 'unsure'])->nullable()
                ->comment('72% accurate - coarse use only, never a precise figure');
            $table->enum('weight_bucket', ['under_1kg', '1_5kg', '5_20kg', '20_100kg', 'over_100kg', 'unsure'])->nullable()
                ->comment('65% accurate - not publishable as a figure; tonnage uses the weights table');

            $table->text('electrical_components')->nullable()
                ->comment('Semicolon-separated raw component strings the model observed');

            $table->string('model', 64)->comment('e.g. gemini-2.0-flash-lite');
            $table->string('prompt_version', 16);
            $table->timestamp('classified_at')->useCurrent();

            $table->unique(['msgid', 'model', 'prompt_version'], 'messages_eee_msgid_model_prompt');

            // The page's hot query is "electrical items in the last N months", so the
            // composite leads on is_eee.
            $table->index(['is_eee', 'classified_at'], 'messages_eee_iseee_classified');
            $table->index('classified_at', 'messages_eee_classified_at');

            // A classification is meaningless once the message is gone.
            $table->foreign('msgid', 'messages_eee_ibfk_1')->references('id')->on('messages')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_eee');
    }
};
