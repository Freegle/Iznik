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
        if (Schema::hasTable('newsfeed_unfollow')) {
            return;
        }

        Schema::create('newsfeed_unfollow', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->unsignedBigInteger('newsfeedid')->index('newsfeedid');
            $table->timestamp('timestamp')->useCurrent();

            $table->unique(['userid', 'newsfeedid'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsfeed_unfollow');
    }
};
