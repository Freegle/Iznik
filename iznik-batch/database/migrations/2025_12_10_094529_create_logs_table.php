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
        if (Schema::hasTable('logs')) {
            return;
        }

        Schema::create('logs', function (Blueprint $table) {
            $table->comment('Logs.  Not guaranteed against loss');
            $table->bigIncrements('id')->comment('Unique ID');
            $table->timestamp('timestamp')->useCurrent()->index('timestamp')->comment('Machine assumed set to GMT');
            $table->unsignedBigInteger('byuser')->nullable()->index('byuser')->comment('User responsible for action, if any');
            $table->enum('type', ['Group', 'Message', 'User', 'Plugin', 'Config', 'StdMsg', 'Location', 'BulkOp', 'Chat'])->nullable();
            // Value order must match the live DB's physical order: ENUM values are
            // stored as 1-based ordinals, and later ALTERs authored from this list
            // must be pure end-appends or they force a full-table COPY rebuild on
            // the live cluster. 'OurEmailFrequency' was hot-fixed onto the live DB
            // as an append, so it comes LAST - not next to 'OurPostingStatus'.
            $table->enum('subtype', ['Created', 'Deleted', 'Received', 'Sent', 'Failure', 'ClassifiedSpam', 'Joined', 'Left', 'Approved', 'Rejected', 'YahooDeliveryType', 'YahooPostingStatus', 'NotSpam', 'Login', 'Hold', 'Release', 'Edit', 'RoleChange', 'Merged', 'Split', 'Replied', 'Mailed', 'Applied', 'Suspect', 'Licensed', 'LicensePurchase', 'YahooApplied', 'YahooConfirmed', 'YahooJoined', 'MailOff', 'EventsOff', 'NewslettersOff', 'RelevantOff', 'Logout', 'Bounce', 'SuspendMail', 'Autoreposted', 'Outcome', 'OurPostingStatus', 'VolunteersOff', 'Autoapproved', 'Unbounce', 'WorryWords', 'NoteAdded', 'PostcodeChange', 'Repost', 'OurEmailFrequency'])->nullable();
            $table->unsignedBigInteger('groupid')->nullable()->index('group')->comment('Any group this log is for');
            $table->unsignedBigInteger('user')->nullable()->index('user')->comment('Any user that this log is about');
            $table->unsignedBigInteger('msgid')->nullable()->index('msgid')->comment('id in the messages table');
            $table->unsignedBigInteger('configid')->nullable()->comment('id in the mod_configs table');
            $table->unsignedBigInteger('stdmsgid')->nullable()->comment('Any stdmsg for this log');
            $table->unsignedBigInteger('bulkopid')->nullable();
            $table->mediumText('text')->nullable();

            $table->index(['timestamp', 'type', 'subtype'], 'timestamp_2');
            $table->index(['type', 'subtype'], 'type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs');
    }
};
