<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-member watermark for "I have cleared my browse count".
 *
 * The browse badge counts open posts in reach with no messages_likes View row. Scrolling a
 * post into view writes that row, one per post, and it is a genuine impression: it feeds the
 * view count posters see and the recommendation funnels. There is no honest way to spend it
 * on a bulk "mark all read" - a member clearing ~1,000 posts (the ordinary backlog) has not
 * viewed them.
 *
 * So clearing moves a watermark instead: one row per member, no per-post writes, nothing
 * added to any analytics table.
 *
 * spatialid is a messages_spatial.id, NOT an arrival or a msgid. Both of those are stamped
 * when the post was written, so a post Pending at the moment of clearing and approved
 * afterwards carries a backdated value (MessageSpatialService inserts arrival = the group
 * arrival) and would fall under the watermark and never be counted again. The spatial row is
 * created when the post enters the feed, so its auto-increment id is the honest "became
 * visible" clock.
 *
 * Mirrors newsfeed_users, which is the same watermark for ChitChat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('browse_cleared')) {
            return;
        }

        Schema::create('browse_cleared', function (Blueprint $table) {
            $table->comment('How far a member has cleared their browse unread count');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->unique('userid');
            $table->unsignedBigInteger('spatialid')->comment('messages_spatial.id cleared up to and including');
            $table->timestamp('timestamp')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browse_cleared');
    }
};
