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
        if (Schema::hasTable('messages_history')) {
            return;
        }

        Schema::create('messages_history', function (Blueprint $table) {
            $table->comment('Message arrivals, used for spam checking');
            $table->bigIncrements('id')->unique('id')->comment('Unique iD');
            $table->unsignedBigInteger('msgid')->nullable()->index('incomingid')->comment('id in the messages table');
            $table->timestamp('arrival')->useCurrent()->index('arrival')->comment('When this message arrived at our server');
            $table->enum('source', ['Yahoo Approved', 'Yahoo Pending', 'Yahoo System', 'Platform'])->nullable()->comment('Source of incoming message');
            $table->string('fromip', 40)->nullable()->index('fromup')->comment('IP we think this message came from');
            $table->string('fromhost', 80)->nullable()->index('fromhost')->comment('Hostname for fromip if resolvable, or NULL');
            $table->unsignedBigInteger('fromuser')->nullable()->index('fromuser');
            $table->string('envelopefrom')->nullable()->index('envelopefrom');
            $table->string('fromname')->nullable()->index('fromname');
            $table->string('fromaddr')->nullable()->index('fromaddr');
            $table->string('envelopeto')->nullable()->index('envelopeto');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid')->comment('Destination group, if identified');
            $table->string('subject', 1024)->nullable()->index('subject');
            $table->string('prunedsubject', 1024)->nullable()->index('prunedsubject')->comment('For spam detection');
            $table->string('messageid')->nullable()->index('message-id');
            $table->boolean('repost')->nullable()->default(false);

            $table->unique(['msgid', 'groupid'], 'msgid');
            $table->primary(['id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_history');
    }
};
