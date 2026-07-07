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
        if (Schema::hasTable('messages_attachments')) {
            return;
        }

        Schema::create('messages_attachments', function (Blueprint $table) {
            $table->comment('Attachments parsed out from messages and resized');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->nullable()->index('incomingid')->comment('id in the messages table');
            $table->tinyInteger('archived')->nullable()->default(0);
            $table->binary('data')->nullable();
            $table->string('hash', 16)->nullable()->index('hash');
            $table->boolean('rotated')->default(false);
            $table->boolean('primary')->default(false);
            $table->string('externaluid', 64)->nullable();
            $table->string('externalurl', 2048)->nullable();
            $table->text('externalmods')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_attachments');
    }
};
