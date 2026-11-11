<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * messages_viewcounts: how many members have viewed each post, one row per post, written nightly by
 * messages:count-views. The ModTools dashboard's network-wide Popular Posts ranks the last 90 days of
 * posts by views; counting that live means reading ~63M messages_likes rows (54s on production), which
 * never fitted inside the dashboard's 40s deadline.
 *
 * arrival is copied from messages so the dashboard can take a date range's most-viewed posts from the
 * (arrival, views, msgid) index alone, without visiting every post in the range. counted_at is when
 * the run that wrote the row started; the dashboard counts live for posts that arrived after the
 * newest one.
 *
 * A new, empty table. Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages_viewcounts')) {
            Schema::create('messages_viewcounts', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('msgid')->unique('messages_viewcounts_msgid');
                $t->dateTime('arrival');
                $t->unsignedInteger('views');
                $t->dateTime('counted_at');
                $t->index(['arrival', 'views', 'msgid'], 'messages_viewcounts_arrival_views');
                $t->index(['counted_at'], 'messages_viewcounts_counted_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_viewcounts');
    }
};
