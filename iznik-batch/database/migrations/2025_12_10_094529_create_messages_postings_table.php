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
        if (Schema::hasTable('messages_postings')) {
            return;
        }

        Schema::create('messages_postings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->index('msgid');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->timestamp('date')->useCurrent();
            $table->boolean('repost')->default(false);
            $table->boolean('autorepost')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_postings');
    }
};
