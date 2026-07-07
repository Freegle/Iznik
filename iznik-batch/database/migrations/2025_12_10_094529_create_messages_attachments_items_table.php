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
        if (Schema::hasTable('messages_attachments_items')) {
            return;
        }

        Schema::create('messages_attachments_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('attid')->index('msgid');
            $table->unsignedBigInteger('itemid')->index('itemid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_attachments_items');
    }
};
