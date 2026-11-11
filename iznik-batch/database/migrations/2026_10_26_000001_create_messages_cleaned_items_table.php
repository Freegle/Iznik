<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * messages_cleaned_items: the single item a post is about, as Gemini reads its subject ("Aluminium
 * walking frame" is a "walking frame"), and that item's key. One row per post. The AI item
 * pictures, the matched-posts email and search all read it. itemkey is NULL when the subject
 * names no drawable item ("Household bits", "Can anyone help?").
 * docs/developers/reference/message-items.md.
 *
 * A new, empty table. Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages_cleaned_items')) {
            Schema::create('messages_cleaned_items', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('msgid')->unique('messages_cleaned_items_msgid');
                $t->string('item', 100);
                $t->string('itemkey', 100)->nullable();
                $t->timestamp('created')->useCurrent();
                $t->index('itemkey', 'messages_cleaned_items_itemkey');
                $t->foreign('msgid')->references('id')->on('messages')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_cleaned_items');
    }
};
