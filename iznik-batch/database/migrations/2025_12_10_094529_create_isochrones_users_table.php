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
        if (Schema::hasTable('isochrones_users')) {
            return;
        }

        Schema::create('isochrones_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->unsignedBigInteger('isochroneid')->index('isochroneid');
            $table->string('nickname', 80)->nullable();

            $table->unique(['userid', 'isochroneid'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('isochrones_users');
    }
};
