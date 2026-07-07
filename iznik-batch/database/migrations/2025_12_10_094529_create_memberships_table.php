<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('memberships')) {
            return;
        }

        Schema::create('memberships', function (Blueprint $table) {
            $table->comment('Which groups users are members of');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid');
            $table->unsignedBigInteger('groupid');
            $table->enum('role', ['Member', 'Moderator', 'Owner'])->default('Member')->index('role');
            $table->enum('collection', ['Approved', 'Pending', 'Banned'])->default('Approved')->index('collection');
            $table->unsignedBigInteger('configid')->nullable()->index('configid')->comment('Configuration used to moderate this group if a moderator');
            $table->timestamp('added')->useCurrent();
            $table->mediumText('settings')->nullable()->comment('Other group settings, e.g. for moderators');
            $table->tinyInteger('syncdelete')->default(0)->comment('Used during member sync');
            $table->unsignedBigInteger('heldby')->nullable()->index('heldby');
            $table->integer('emailfrequency')->default(24)->comment('In hours; -1 immediately, 0 never');
            $table->boolean('eventsallowed')->nullable()->default(true);
            $table->bigInteger('volunteeringallowed')->default(1);
            $table->enum('ourPostingStatus', ['MODERATED', 'DEFAULT', 'PROHIBITED', 'UNMODERATED'])->nullable()->comment('For Yahoo groups, NULL; for ours, the posting status');
            $table->timestamp('reviewrequestedat')->nullable()->index('reviewrequestedat');
            $table->string('reviewreason')->nullable();
            $table->timestamp('reviewedat')->nullable();

            $table->index(['added', 'groupid'], 'added_groupid');
            $table->index(['groupid', 'collection'], 'groupid');
            $table->index(['groupid', 'role'], 'groupid_2');
            $table->index(['userid', 'role'], 'userid');
            $table->unique(['userid', 'groupid'], 'userid_groupid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
