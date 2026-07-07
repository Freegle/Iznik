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
        if (Schema::hasTable('users_images')) {
            return;
        }

        Schema::create('users_images', function (Blueprint $table) {
            $table->comment('Attachments parsed out from messages and resized');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->nullable()->index('incomingid')->comment('id in the users table');
            $table->string('contenttype', 80);
            $table->boolean('default')->default(false);
            $table->string('url', 1024)->nullable();
            $table->tinyInteger('archived')->nullable()->default(0);
            $table->binary('data')->nullable();
            $table->mediumText('identification')->nullable();
            $table->string('hash', 16)->nullable()->index('hash');
            $table->string('externaluid', 64)->nullable();
            $table->text('externalmods')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_images');
    }
};
