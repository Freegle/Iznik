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
        if (Schema::hasTable('messages_index')) {
            return;
        }

        Schema::create('messages_index', function (Blueprint $table) {
            $table->comment('For indexing messages for search keywords');
            $table->unsignedBigInteger('msgid');
            $table->unsignedBigInteger('wordid');
            $table->bigInteger('arrival')->index('arrival')->comment('We prioritise recent messages');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');

            $table->unique(['msgid', 'wordid'], 'msgid');
            $table->index(['wordid', 'groupid'], 'wordid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_index');
    }
};
