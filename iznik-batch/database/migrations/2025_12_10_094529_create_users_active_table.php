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
        if (Schema::hasTable('users_active')) {
            return;
        }

        Schema::create('users_active', function (Blueprint $table) {
            $table->comment('Track when users are active hourly');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->nullable();
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');

            $table->unique(['userid', 'timestamp'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_active');
    }
};
