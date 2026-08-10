<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * messages_groups.contentcheck_recheck_at - "this row has been checked, but its content
 * has changed since, so check it again".
 *
 * Editing a message re-queued its content check by clearing contentcheck_checked_at. But
 * that stamp is also what makes a Pending post visible: both the mod Pending list
 * (message_list.go) and the work counts (groupWork.go, session.go) hide rows that have
 * not been checked, so that a brand-new post is not shown before the automated checks
 * have had their say. Clearing it on edit therefore made the post the moderator had just
 * edited vanish from their queue - list and badge together - until the next batch pass
 * re-stamped it, typically half a minute later, and it came back only on a manual reload
 * (Discourse 10001).
 *
 * Splitting the two meanings fixes that. "Never checked" stays contentcheck_checked_at IS
 * NULL and still hides the post; "checked, then edited" is this column, and does not.
 * The batch picks up either.
 *
 * Nullable with no default, so a NULL means "nothing outstanding" and the column is safe
 * to add ahead of the code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('messages_groups', 'contentcheck_recheck_at')) {
            return;
        }

        Schema::table('messages_groups', function (Blueprint $table) {
            $table->timestamp('contentcheck_recheck_at')->nullable()->after('contentcheck_reasons');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('messages_groups', 'contentcheck_recheck_at')) {
            return;
        }

        Schema::table('messages_groups', function (Blueprint $table) {
            $table->dropColumn('contentcheck_recheck_at');
        });
    }
};
