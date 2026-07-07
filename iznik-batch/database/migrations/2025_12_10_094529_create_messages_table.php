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
        if (Schema::hasTable('messages')) {
            return;
        }

        Schema::create('messages', function (Blueprint $table) {
            $table->comment('All our messages');
            $table->bigIncrements('id')->comment('Unique iD');
            $table->timestamp('arrival')->useCurrent()->index('arrival_3')->comment('When this message arrived at our server');
            $table->timestamp('date')->nullable()->index('date')->comment('When this message was created, e.g. Date header');
            $table->timestamp('deleted')->nullable()->index('deleted')->comment('When this message was deleted');
            $table->unsignedBigInteger('heldby')->nullable()->index('heldby')->comment('If this message is held by a moderator');
            $table->enum('source', ['Yahoo Approved', 'Yahoo Pending', 'Yahoo System', 'Platform', 'Email'])->nullable()->comment('Source of incoming message');
            $table->string('sourceheader', 80)->nullable()->index('sourceheader')->comment('Any source header, e.g. X-Freegle-Source');
            $table->string('fromip', 40)->nullable()->index('fromup')->comment('IP we think this message came from');
            $table->string('fromcountry', 2)->nullable()->comment('fromip geocoded to country');
            $table->longText('message')->comment('The unparsed message');
            $table->unsignedBigInteger('fromuser')->nullable()->index('fromuser');
            $table->string('envelopefrom')->nullable()->index('envelopefrom');
            $table->string('fromname')->nullable();
            $table->string('fromaddr')->nullable();
            $table->string('envelopeto')->nullable()->index('envelopeto');
            $table->string('replyto')->nullable();
            $table->string('subject')->nullable()->index('subject');
            $table->string('suggestedsubject')->nullable();
            $table->enum('type', ['Offer', 'Taken', 'Wanted', 'Received', 'Admin', 'Other'])->nullable()->index('type')->comment('For reuse groups, the message categorisation');
            $table->string('messageid')->nullable()->unique('message-id');
            $table->string('tnpostid', 80)->nullable()->index('tnpostid')->comment('If this message came from Trash Nothing, the unique post ID');
            $table->longText('textbody')->nullable();
            $table->longText('htmlbody')->nullable();
            $table->integer('retrycount')->default(0)->comment('We might fail to route, and later retry');
            $table->timestamp('retrylastfailure')->nullable()->index('retrylastfailure');
            $table->string('spamtype', 50)->nullable()->comment('Type of spam detected - now VARCHAR to support all SpamCheckService reasons');
            $table->string('spamreason')->nullable()->comment('Why we think this message may be spam');
            $table->decimal('lat', 10, 6)->nullable()->index('lat');
            $table->decimal('lng', 10, 6)->nullable()->index('lng');
            $table->unsignedBigInteger('locationid')->nullable()->index('locationid');
            $table->unsignedBigInteger('editedby')->nullable();
            $table->timestamp('editedat')->nullable();
            $table->integer('availableinitially')->default(1);
            $table->integer('availablenow')->default(1);
            $table->string('lastroute', 20)->nullable();
            $table->boolean('deliverypossible')->default(false);
            $table->date('deadline')->nullable();

            $table->index(['arrival', 'sourceheader'], 'arrival');
            $table->index(['arrival', 'fromaddr'], 'arrival_2');
            $table->index(['fromaddr', 'subject'], 'fromaddr');
            $table->index(['fromuser', 'arrival', 'type'], 'fromuser_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
