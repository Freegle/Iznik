<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track moderator review of auto-published posts.
 *
 * Posts that go live without a manual approval (auto-moderated "Checked" members
 * and trusted "Trusted" members) have no field recording that a moderator has
 * since eyeballed them — approvedby is NULL, contentcheck_* is only the automated
 * pass, and heldby/spamreason/reviewrequestedat are unrelated. These columns give
 * the ModTools Checked/Trusted oversight queues a real, clearable "unchecked"
 * count: a mod marks a post checked, and it leaves the queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->timestamp('checkedat')->nullable()->after('rejectedat');
            $table->unsignedBigInteger('checkedby')->nullable()->after('checkedat');

            $table->foreign('checkedby')->references('id')->on('users')->onDelete('set null');
            // Composite index supports the "unchecked in this group" oversight query.
            $table->index(['groupid', 'checkedat'], 'messages_groups_groupid_checkedat_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->dropForeign(['checkedby']);
            $table->dropIndex('messages_groups_groupid_checkedat_idx');
            $table->dropColumn(['checkedat', 'checkedby']);
        });
    }
};
