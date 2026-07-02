<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pinned posts.
 *
 * A pinned post floats to the TOP of the browse feed whenever it already qualifies to
 * appear there (i.e. it still passes the normal reach / member-group visibility gate),
 * and is force-included at the TOP of every daily and immediate digest while it is still
 * open. Intended for paid bulk-offer "clearances" where an offerer pays Freegle to
 * dispose of goods and wants maximum visibility until the goods are gone.
 *
 * One row per pinned post (msgid is the primary key); a post is pinned iff a row exists.
 * Follows the established bulk-offer pattern (see messages_bulk_access / messages_ai_declined):
 * a tiny side table keyed by msgid that adds NO column to the huge core `messages` table
 * (which is too big to ALTER), and deliberately no FK to it - the row is created on demand
 * and is a harmless orphan if the post is later removed. Pinning ends naturally when the
 * post closes (taken/withdrawn/expired), since the feed and digest only ever show open posts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages_pinned')) {
            return;
        }

        Schema::create('messages_pinned', function (Blueprint $table) {
            $table->comment('Posts pinned to the top of browse and always in the digest while open (e.g. paid bulk clearances).');
            $table->unsignedBigInteger('msgid')->primary();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_pinned');
    }
};
