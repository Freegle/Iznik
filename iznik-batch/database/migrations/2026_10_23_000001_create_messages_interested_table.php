<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * messages_interested: a member pressed "I'm interested" on a post, a quick sign of interest before
 * replying. One row per member and post. chasedat is when the chase-up email named it, so it is named
 * once. docs/developers/reference/ux-experiments.md, "An I'm interested button".
 *
 * A new, empty table. Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages_interested')) {
            Schema::create('messages_interested', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('msgid');
                $t->unsignedBigInteger('userid');
                $t->timestamp('added')->useCurrent();
                $t->timestamp('chasedat')->nullable();
                $t->unique(['userid', 'msgid'], 'messages_interested_user_msg');
                $t->index('msgid', 'messages_interested_msgid');
                $t->index(['chasedat', 'added'], 'messages_interested_chase');
                $t->foreign('msgid')->references('id')->on('messages')->onDelete('cascade');
                $t->foreign('userid')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_interested');
    }
};
