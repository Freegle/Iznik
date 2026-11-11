<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * messages_autosplit: what the auto-splitter decided about a post, one row per post it has
 * looked at. A post is an automatic split, not a clearance, while its row is 'Split': it has
 * messages_bulk_items rows like a clearance, and this row is how everything that treats
 * clearances differently tells them apart. 'NotSplit' records a post left alone, 'Shadow' one
 * that would have been split while the job ran in shadow mode, and 'Undone' a split the poster
 * took back, which is never split again.
 *
 * items and photos keep the models' answers; createdattachments the crop rows the split added,
 * so undo can remove them; the two original counts what undo puts back.
 * docs/developers/reference/auto-split.md.
 *
 * A new, empty table. Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages_autosplit')) {
            Schema::create('messages_autosplit', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('msgid')->unique('messages_autosplit_msgid');
                $t->enum('state', ['Shadow', 'Split', 'NotSplit', 'Undone']);
                $t->string('kind', 32)->nullable()->comment('The text splitter\'s kind: single, set, bundle, several_items...');
                $t->json('items')->nullable()->comment('The text splitter\'s item list');
                $t->json('photos')->nullable()->comment('What each photo was decided to be');
                $t->json('createdattachments')->nullable()->comment('messages_attachments ids of the crops the split added');
                $t->unsignedInteger('originalavailableinitially')->nullable();
                $t->unsignedInteger('originalavailablenow')->nullable();
                $t->string('model', 64)->nullable();
                $t->unsignedInteger('tokensin')->default(0);
                $t->unsignedInteger('tokensout')->default(0);
                $t->timestamp('created')->useCurrent();
                $t->timestamp('undoneat')->nullable();
                $t->index(['state', 'created'], 'messages_autosplit_state_created');
                $t->foreign('msgid')->references('id')->on('messages')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_autosplit');
    }
};
