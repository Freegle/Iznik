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
        if (Schema::hasTable('users_stories_images')) {
            return;
        }

        Schema::create('users_stories_images', function (Blueprint $table) {
            $table->comment('Attachments parsed out from messages and resized');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('storyid')->nullable()->index('incomingid');
            $table->string('contenttype', 80);
            $table->boolean('default')->default(false);
            $table->string('url', 1024)->nullable();
            $table->tinyInteger('archived')->nullable()->default(0);
            $table->binary('data')->nullable();
            $table->string('hash', 16)->nullable()->index('hash');
            $table->timestamp('timestamp')->useCurrent();
            $table->string('externaluid', 64)->nullable();
            $table->text('externalmods')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_stories_images');
    }
};
