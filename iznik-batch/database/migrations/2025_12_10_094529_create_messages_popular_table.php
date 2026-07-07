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
        if (Schema::hasTable('messages_popular')) {
            return;
        }

        Schema::create('messages_popular', function (Blueprint $table) {
            $table->comment('Recent popular messages');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->index('msgid');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');
            $table->boolean('shared')->default(false);
            $table->boolean('declined')->default(false);
            $table->boolean('expired')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_popular');
    }
};
