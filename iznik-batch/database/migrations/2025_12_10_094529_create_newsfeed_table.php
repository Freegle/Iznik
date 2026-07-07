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
        if (Schema::hasTable('newsfeed')) {
            return;
        }

        Schema::create('newsfeed', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('timestamp')->useCurrent();
            $table->timestamp('added')->useCurrent();
            $table->enum('type', ['Message', 'CommunityEvent', 'VolunteerOpportunity', 'CentralPublicity', 'Alert', 'Story', 'ReferToWanted', 'ReferToOffer', 'ReferToTaken', 'ReferToReceived', 'AboutMe', 'Noticeboard'])->default('Message');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->unsignedBigInteger('imageid')->nullable()->index('imageid');
            $table->unsignedBigInteger('msgid')->nullable()->index('msgid');
            $table->unsignedBigInteger('replyto')->nullable()->index('replyto');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->unsignedBigInteger('eventid')->nullable()->index('eventid');
            $table->unsignedBigInteger('volunteeringid')->nullable()->index('volunteeringid');
            $table->unsignedBigInteger('publicityid')->nullable()->index('publicityid');
            $table->unsignedBigInteger('storyid')->nullable()->index('storyid');
            $table->text('message')->nullable();
            $table->geography('position', null, 3857);
            $table->boolean('reviewrequired')->default(false);
            $table->timestamp('deleted')->nullable();
            $table->unsignedBigInteger('deletedby')->nullable();
            $table->timestamp('hidden')->nullable();
            $table->unsignedBigInteger('hiddenby')->nullable();
            $table->boolean('closed')->default(false);
            $table->text('html')->nullable();
            $table->boolean('pinned')->default(false);
            $table->string('location', 80)->nullable();

            $table->index(['pinned', 'timestamp'], 'pinned');
            $table->spatialIndex(['position'], 'position');
            $table->index(['timestamp', 'replyto', 'deleted', 'reviewrequired', 'type'], 'timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsfeed');
    }
};
