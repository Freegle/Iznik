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
        if (Schema::hasTable('merges')) {
            return;
        }

        Schema::create('merges', function (Blueprint $table) {
            $table->comment('Offers of merges to members');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user1');
            $table->unsignedBigInteger('user2');
            $table->timestamp('offered')->nullable()->useCurrent();
            $table->timestamp('accepted')->nullable();
            $table->timestamp('rejected')->nullable();
            $table->unsignedBigInteger('offeredby');
            $table->string('uid', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merges');
    }
};
