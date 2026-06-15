<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a hold-until timestamp to messages_groups for the auto-approve countdown.
 *
 * When a moderator opens the Pending page, the server bumps autoapprove_hold_until
 * to at least NOW() + 10 minutes for every fetched row (extend-only). Both
 * AutoApproveCleanService and AutoApproveService skip rows where the column is
 * set and still in the future, giving mods a guaranteed review window.
 *
 * NULL means "no hold" — the existing auto-approve timing applies unchanged.
 * No backfill: all existing rows default to NULL, so the behaviour of the two
 * auto-approve services is identical to before the migration is run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->timestamp('autoapprove_hold_until')->nullable()->after('quality_sample');
            // Keeps the candidate-query predicate fast: both auto-approvers gain
            // AND (mg.autoapprove_hold_until IS NULL OR mg.autoapprove_hold_until <= NOW())
            $table->index(['groupid', 'autoapprove_hold_until'], 'messages_groups_groupid_hold_until_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->dropIndex('messages_groups_groupid_hold_until_idx');
            $table->dropColumn('autoapprove_hold_until');
        });
    }
};
