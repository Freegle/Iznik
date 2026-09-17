<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * messages_drafts.heldby - a mod hold captured from messages_groups when a member's
 * pending post is taken back to draft (RejectToDraft), so JoinAndPost can restore it
 * on the SAME group's row when the member reposts, without a mod having released it
 * (Discourse 9946/8). NULL when the post wasn't held, or once the member switches to
 * a different destination group via PATCH - a hold belongs to the group it was
 * applied on, not to the post itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('messages_drafts') || Schema::hasColumn('messages_drafts', 'heldby')) {
            return;
        }

        Schema::table('messages_drafts', function (Blueprint $table) {
            $table->unsignedBigInteger('heldby')->nullable()->after('groupid');
            $table->foreign('heldby')->references('id')->on('users')->onDelete('set null');
            $table->index('heldby', 'messages_drafts_heldby_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('messages_drafts') || !Schema::hasColumn('messages_drafts', 'heldby')) {
            return;
        }

        Schema::table('messages_drafts', function (Blueprint $table) {
            $table->dropForeign(['heldby']);
            $table->dropIndex('messages_drafts_heldby_idx');
            $table->dropColumn('heldby');
        });
    }
};
