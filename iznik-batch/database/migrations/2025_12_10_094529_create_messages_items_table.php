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
        if (Schema::hasTable('messages_items')) {
            return;
        }

        Schema::create('messages_items', function (Blueprint $table) {
            $table->comment('Where known, items for our message');
            $table->unsignedBigInteger('msgid');
            $table->unsignedBigInteger('itemid')->index('itemid');

            $table->unique(['msgid', 'itemid'], 'msgid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_items');
    }
};
