<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_image_aliases: a name that uses another name's AI item picture, so a variant reuses a
 * picture we already have instead of a new one being generated. docs/developers/reference/ai-item-images.md.
 *
 * Two kinds of row, told apart by method:
 *  - 'item': a post's wording ("3 bags of cat litter") pointing at the picture for its item.
 *  - 'itemkey': an item's key ("cat litter") pointing at the picture drawn for it.
 *
 * The Go API reads the first kind so the post form shows the picture straight away. A moderator
 * removing a borrowed picture deletes the alias rather than counting against the picture.
 *
 * A new, empty table. Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_image_aliases')) {
            Schema::create('ai_image_aliases', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('name', 255)->unique('ai_image_aliases_name');
                $t->unsignedBigInteger('aiimageid');
                $t->string('method', 16);
                $t->timestamp('created')->useCurrent();
                $t->index('aiimageid', 'ai_image_aliases_aiimageid');
                $t->foreign('aiimageid')->references('id')->on('ai_images')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_image_aliases');
    }
};
