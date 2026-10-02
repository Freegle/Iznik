<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mark posts held back by the auto-approve quality-check sample.
 *
 * AutoApproveCleanService holds a configurable percentage of otherwise-eligible
 * posts in Pending for a moderator to review, instead of auto-approving them. To
 * measure whether auto-approval is working (the moderation-stats dashboard), we
 * need to know which posts were sampled so we can compare the mod's verdict on
 * the sample against the post-go-live error rate of the auto-approved population.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->boolean('quality_sample')->default(false)->after('checkedby');
            $table->index(['groupid', 'quality_sample'], 'messages_groups_groupid_qsample_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->dropIndex('messages_groups_groupid_qsample_idx');
            $table->dropColumn('quality_sample');
        });
    }
};
