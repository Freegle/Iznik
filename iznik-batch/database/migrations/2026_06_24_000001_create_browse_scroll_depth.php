<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * browse_scroll_depth: one row per browse-feed session recording the FURTHEST feed position
 * (0-based item index) the member scrolled to. Powers the sysadmin "Scrolling" tab's scroll-depth
 * curve - for each position N, the fraction of sessions that reached at least N - the browse-feed
 * analogue of the digest click-by-position chart (email_tracking_clicks.link_position).
 *
 * Written by the Go apiv2 scroll-depth handler, one record per session (the browse page sends the
 * max position reached on leave/hide). userid is NULL for logged-out browsing. items_available is
 * the feed length at record time, so "stopped early" is distinguishable from "reached the end".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('browse_scroll_depth')) {
            Schema::create('browse_scroll_depth', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('userid')->nullable();
                $t->unsignedInteger('max_position')
                    ->comment('Furthest 0-based feed item index reached in the session');
                $t->unsignedInteger('items_available')->nullable()
                    ->comment('Feed length when recorded; tells "stopped early" from "reached the end"');
                $t->string('context', 16)->nullable()
                    ->comment("Which feed: 'browse' or 'search'");
                $t->timestamp('created_at')->useCurrent();
                $t->index('created_at', 'bsd_created_at');
                $t->index('userid', 'bsd_userid');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('browse_scroll_depth');
    }
};
