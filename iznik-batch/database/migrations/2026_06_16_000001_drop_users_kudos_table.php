<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the users_kudos table.
     *
     * Kudos scores were calculated daily by the `users:update-kudos` job but the
     * stored values were never read by any live, API or front-end code path, so
     * the whole feature has been retired. See the matching code removal in this
     * change set (UserManagementService, UpdateKudosCommand, the housekeeper
     * registry entry, and the legacy V1 User::*Kudos / possibleMods methods).
     */
    public function up(): void
    {
        Schema::dropIfExists('users_kudos');
    }

    /**
     * Recreate the users_kudos table (schema as it existed before retirement).
     */
    public function down(): void
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
            $table->foreign(['userid'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }
};
