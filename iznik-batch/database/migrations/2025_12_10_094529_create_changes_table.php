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
        if (Schema::hasTable('changes')) {
            return;
        }

        Schema::create('changes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('timestamp')->useCurrent()->index('timestamp');
            $table->enum('type', ['INSERT', 'UPDATE', 'DELETE']);
            $table->unsignedBigInteger('msgid')->nullable()->index('msgid');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->unsignedBigInteger('chatid')->nullable()->index('chatid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('changes');
    }
};
