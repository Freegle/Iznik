<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per post that must never ripple out again.
 *
 * When a moderator of a post's home community moves it back to pending, the post is withdrawn
 * from every community it had rippled into. Those moderators have already had the post in their
 * area once; showing it to them again, or putting it in their pending queue, is work for a
 * decision the home community has made. The row is the durable record: a reach row cannot be,
 * because a repost or an expiry removes it and the ripple engine then starts a new one.
 *
 * Production SQL: 2026_10_05_000001_create_rippling_blocked_table_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rippling_blocked')) {
            return;
        }

        Schema::create('rippling_blocked', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->unique();
            // The moderator whose action blocked it.
            $table->unsignedBigInteger('byuser')->nullable();
            $table->string('reason', 80);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('msgid')->references('id')->on('messages')->cascadeOnDelete();
            $table->foreign('byuser')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_blocked');
    }
};
