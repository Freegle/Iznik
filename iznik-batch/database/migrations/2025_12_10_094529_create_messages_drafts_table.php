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
        if (Schema::hasTable('messages_drafts')) {
            return;
        }

        Schema::create('messages_drafts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->unique('msgid');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->string('session')->nullable()->index('session');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_drafts');
    }
};
