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
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id')->unique('id');
            $table->string('yahooUserId', 20)->nullable()->unique('yahoouserid')->comment('Unique ID of user on Yahoo if known');
            $table->string('firstname')->nullable()->index('firstname');
            $table->string('lastname')->nullable()->index('lastname');
            $table->string('fullname')->nullable()->index('fullname');
            $table->set('systemrole', ['User', 'Moderator', 'Support', 'Admin'])->default('User')->index('systemrole')->comment('System-wide roles');
            $table->timestamp('added')->useCurrent();
            $table->timestamp('lastaccess')->useCurrent();
            $table->mediumText('settings')->nullable()->comment('JSON-encoded settings');
            $table->tinyInteger('gotrealemail')->default(0)->index('gotrealemail')->comment('Until migrated, whether polled FD/TN to get real email');
            $table->string('yahooid', 40)->nullable()->unique('yahooid')->comment('Any known YahooID for this user');
            $table->integer('licenses')->default(0)->comment('Any licenses not added to groups');
            $table->tinyInteger('newslettersallowed')->default(1)->comment('Central mails');
            $table->tinyInteger('relevantallowed')->default(1);
            $table->date('onholidaytill')->nullable();
            $table->boolean('marketingconsent')->default(false)->comment('Whether we have PECR consent');
            $table->unsignedBigInteger('lastlocation')->nullable()->index('lastlocation');
            $table->timestamp('lastrelevantcheck')->nullable()->index('lastrelevantcheck');
            $table->timestamp('lastidlechaseup')->nullable();
            $table->boolean('bouncing')->default(false)->comment('Whether preferred email has been determined to be bouncing');
            $table->set('permissions', ['BusinessCardsAdmin', 'Newsletter', 'NationalVolunteers', 'Teams', 'GiftAid', 'SpamAdmin'])->nullable();
            $table->unsignedInteger('invitesleft')->nullable()->default(10);
            $table->string('source', 40)->nullable();
            $table->enum('chatmodstatus', ['Moderated', 'Unmoderated', 'Fully'])->default('Moderated');
            $table->timestamp('deleted')->nullable()->index('deleted');
            $table->boolean('inventedname')->default(false);
            $table->enum('newsfeedmodstatus', ['Unmoderated', 'Moderated', 'Suppressed', ''])->default('Unmoderated');
            $table->integer('replyambit')->default(0);
            $table->enum('engagement', ['New', 'Occasional', 'Frequent', 'Obsessed', 'Inactive', 'Dormant'])->nullable();
            $table->enum('trustlevel', ['Declined', 'Excluded', 'Basic', 'Moderate', 'Advanced'])->nullable();
            $table->timestamp('lastupdated')->nullable()->index('lastupdated');
            $table->unsignedBigInteger('tnuserid')->nullable();
            $table->unsignedBigInteger('ljuserid')->nullable()->unique('ljuserid');
            $table->timestamp('forgotten')->nullable()->index('forgotten');

            $table->index(['added', 'lastaccess'], 'added');
            $table->index(['firstname', 'lastname'], 'firstname_2');
            $table->primary(['id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
