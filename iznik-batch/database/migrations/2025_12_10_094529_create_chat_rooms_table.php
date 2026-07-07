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
        if (Schema::hasTable('chat_rooms')) {
            return;
        }

        Schema::create('chat_rooms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->enum('chattype', ['Mod2Mod', 'User2Mod', 'User2User', 'Group'])->default('User2User')->index('chattype');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid')->comment('Restricted to a group');
            $table->unsignedBigInteger('user1')->nullable()->index('user1')->comment('For DMs');
            $table->unsignedBigInteger('user2')->nullable()->index('user2')->comment('For DMs');
            $table->string('description', 80)->nullable();
            $table->timestamp('created')->nullable()->useCurrent();
            $table->enum('synctofacebook', ['Dont', 'RepliedOnFacebook', 'RepliedOnPlatform', 'PostedLink'])->default('Dont')->index('synctofacebook');
            $table->unsignedBigInteger('synctofacebookgroupid')->nullable()->index('synctofacebookgroupid');
            $table->timestamp('latestmessage')->nullable()->useCurrent()->comment('Really when chat last active');
            $table->unsignedInteger('msgvalid')->default(0);
            $table->unsignedInteger('msginvalid')->default(0);
            $table->boolean('flaggedspam')->default(false);
            $table->unsignedBigInteger('ljofferid')->nullable();

            $table->index(['groupid', 'latestmessage', 'chattype'], 'room3');
            $table->index(['user1', 'chattype', 'latestmessage'], 'rooms');
            $table->index(['user2', 'chattype', 'latestmessage'], 'rooms2');
            $table->index(['chattype', 'latestmessage'], 'typelatest');
            $table->unique(['user1', 'user2', 'chattype'], 'user1_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_rooms');
    }
};
