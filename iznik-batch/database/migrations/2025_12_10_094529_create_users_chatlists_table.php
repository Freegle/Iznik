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
        if (Schema::hasTable('users_chatlists')) {
            return;
        }

        Schema::create('users_chatlists', function (Blueprint $table) {
            $table->comment('Cache of lists of chats for performance');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->boolean('expired')->default(false);
            $table->string('key');
            $table->longText('chatlist');
            $table->boolean('background')->default(false);

            $table->unique(['userid', 'key'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_chatlists');
    }
};
