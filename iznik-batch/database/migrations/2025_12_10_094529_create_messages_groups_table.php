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
        if (Schema::hasTable('messages_groups')) {
            return;
        }

        Schema::create('messages_groups', function (Blueprint $table) {
            $table->comment('The state of the message on each group');
            $table->unsignedBigInteger('msgid')->comment('id in the messages table');
            $table->unsignedBigInteger('groupid');
            $table->enum('collection', ['Incoming', 'Pending', 'Approved', 'Spam', 'QueuedYahooUser', 'Rejected', 'QueuedUser'])->nullable()->index('collection');
            $table->timestamp('arrival')->useCurrent();
            $table->tinyInteger('autoreposts')->default(0)->comment('How many times this message has been auto-reposted');
            $table->timestamp('lastautopostwarning')->nullable();
            $table->timestamp('lastchaseup')->nullable();
            $table->boolean('deleted')->default(false)->index('deleted');
            $table->boolean('senttoyahoo')->default(false);
            $table->string('yahoopendingid', 20)->nullable()->comment('For Yahoo messages, pending id if relevant');
            $table->string('yahooapprovedid', 20)->nullable()->comment('For Yahoo messages, approved id if relevant');
            $table->string('yahooapprove')->nullable()->comment('For Yahoo messages, email to trigger approve if relevant');
            $table->string('yahooreject')->nullable()->comment('For Yahoo messages, email to trigger reject if relevant');
            $table->unsignedBigInteger('approvedby')->nullable()->index('approvedby')->comment('Mod who approved this post (if any)');
            $table->timestamp('approvedat')->nullable();
            $table->timestamp('rejectedat')->nullable();
            $table->enum('msgtype', ['Offer', 'Taken', 'Wanted', 'Received', 'Admin', 'Other'])->nullable()->comment('In here for performance optimisation');

            $table->index(['arrival', 'groupid', 'msgtype'], 'arrival');
            $table->index(['groupid', 'collection', 'deleted', 'arrival'], 'groupid');
            $table->unique(['groupid', 'yahoopendingid'], 'groupid_2');
            $table->unique(['groupid', 'yahooapprovedid'], 'groupid_3');
            $table->index(['approvedby', 'groupid', 'arrival'], 'lastapproved');
            $table->index(['msgid', 'groupid', 'collection', 'arrival'], 'messageid');
            $table->unique(['msgid', 'groupid'], 'msgid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_groups');
    }
};
