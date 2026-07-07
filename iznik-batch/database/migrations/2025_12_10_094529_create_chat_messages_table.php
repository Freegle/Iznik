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
        if (Schema::hasTable('chat_messages')) {
            return;
        }

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chatid')->index('chatid');
            $table->unsignedBigInteger('userid')->index('userid')->comment('From');
            $table->enum('type', ['Default', 'System', 'ModMail', 'Interested', 'Promised', 'Reneged', 'ReportedUser', 'Completed', 'Image', 'Address', 'Nudge', 'Schedule', 'ScheduleUpdated', 'Reminder'])->default('Default');
            $table->enum('reportreason', ['Spam', 'Other', 'Last', 'Force', 'Fully', 'TooMany', 'User', 'UnknownMessage', 'SameImage', 'DodgyImage'])->nullable();
            $table->unsignedBigInteger('refmsgid')->nullable()->index('msgid');
            $table->unsignedBigInteger('refchatid')->nullable()->index('refchatid');
            $table->unsignedBigInteger('imageid')->nullable()->index('imageid');
            $table->timestamp('date')->useCurrent();
            $table->text('message')->nullable();
            $table->tinyInteger('platform')->default(1)->comment('Whether this was created on the platform vs email');
            $table->boolean('seenbyall')->default(false);
            $table->boolean('mailedtoall')->default(false);
            $table->boolean('reviewrequired')->default(false)->index('reviewrequired')->comment('Whether a volunteer should review before it\'s passed on');
            $table->unsignedBigInteger('reviewedby')->nullable()->index('reviewedby')->comment('User id of volunteer who reviewed it');
            $table->boolean('reviewrejected')->default(false);
            $table->integer('spamscore')->nullable()->comment('SpamAssassin score for mail replies');
            $table->string('facebookid')->nullable();
            $table->unsignedBigInteger('scheduleid')->nullable()->index('scheduleid');
            $table->boolean('replyexpected')->nullable();
            $table->boolean('replyreceived')->default(false);
            $table->boolean('processingrequired')->default(false)->index('processingrequired');
            $table->boolean('processingsuccessful')->default(false);
            $table->boolean('confirmrequired')->default(false);
            $table->boolean('deleted')->default(false);

            $table->index(['chatid', 'date'], 'chatid_2');
            $table->index(['chatid', 'id', 'userid', 'date'], 'chatmax');
            $table->index(['date', 'seenbyall'], 'date');
            $table->index(['refchatid'], 'refchatid_2');
            $table->index(['userid', 'date', 'refmsgid', 'type'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
