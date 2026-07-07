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
        if (Schema::hasTable('users_stories_likes')) {
            return;
        }

        Schema::create('users_stories_likes', function (Blueprint $table) {
            $table->unsignedBigInteger('storyid')->index('storyid');
            $table->unsignedBigInteger('userid')->index('userid');

            $table->unique(['storyid', 'userid'], 'storyid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_stories_likes');
    }
};
