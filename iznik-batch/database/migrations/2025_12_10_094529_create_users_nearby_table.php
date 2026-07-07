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
        if (Schema::hasTable('users_nearby')) {
            return;
        }

        Schema::create('users_nearby', function (Blueprint $table) {
            $table->unsignedBigInteger('userid')->index('userid');
            $table->unsignedBigInteger('msgid')->index('msgid');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');

            $table->unique(['userid', 'msgid'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_nearby');
    }
};
