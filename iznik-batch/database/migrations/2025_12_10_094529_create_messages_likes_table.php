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
        if (Schema::hasTable('messages_likes')) {
            return;
        }

        Schema::create('messages_likes', function (Blueprint $table) {
            $table->unsignedBigInteger('msgid');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->enum('type', ['Love', 'Laugh', 'View']);
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->integer('count')->default(1);

            $table->index(['msgid', 'type'], 'msgid');
            $table->unique(['msgid', 'userid', 'type'], 'msgid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_likes');
    }
};
