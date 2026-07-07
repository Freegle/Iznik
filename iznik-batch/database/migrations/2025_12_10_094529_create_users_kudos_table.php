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
        if (Schema::hasTable('users_kudos')) {
            return;
        }

        Schema::create('users_kudos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('kudos')->default(0);
            $table->unsignedBigInteger('userid')->unique('userid');
            $table->integer('posts')->default(0);
            $table->integer('chats')->default(0);
            $table->integer('newsfeed')->default(0);
            $table->integer('events')->default(0);
            $table->integer('vols')->default(0);
            $table->boolean('facebook')->default(false);
            $table->boolean('platform')->default(false);
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_kudos');
    }
};
