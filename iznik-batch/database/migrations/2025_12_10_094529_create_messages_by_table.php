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
        if (Schema::hasTable('messages_by')) {
            return;
        }

        Schema::create('messages_by', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->index('msgid');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->timestamp('timestamp')->useCurrent()->index('timestamp');
            $table->integer('count')->default(1);

            $table->unique(['msgid', 'userid'], 'msgid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_by');
    }
};
