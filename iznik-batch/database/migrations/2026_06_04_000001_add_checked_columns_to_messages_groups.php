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
 *
 * Two columns and nothing else, on purpose. There is no foreign key on checkedby (nothing
 * joins on it, a deleted moderator's id still means "a moderator checked it", and on
 * messages_groups, about 10 million rows, adding one needs foreign_key_checks off to run
 * in place and builds an index under the cluster-wide lock) and no (groupid, checkedat)
 * index: the Check queue reads the existing `groupid` index (groupid, collection, deleted,
 * arrival) on the seven-day arrival window and filters checkedat on the few rows it finds,
 * where an index leading with groupid and checkedat would walk every unchecked row the
 * community has ever had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->timestamp('checkedat')->nullable()->after('rejectedat');
            $table->unsignedBigInteger('checkedby')->nullable()->after('checkedat');
        });
    }

    public function down(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->dropColumn(['checkedat', 'checkedby']);
        });
    }
};
