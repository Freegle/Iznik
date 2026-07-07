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
        if (Schema::hasTable('users_modmails')) {
            return;
        }

        Schema::create('users_modmails', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid');
            $table->unsignedBigInteger('logid')->unique('logid');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->unsignedBigInteger('groupid');

            $table->index(['userid', 'groupid'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_modmails');
    }
};
