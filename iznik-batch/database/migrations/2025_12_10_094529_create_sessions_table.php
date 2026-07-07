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
        if (Schema::hasTable('sessions')) {
            return;
        }

        Schema::create('sessions', function (Blueprint $table) {
            $table->bigIncrements('id')->unique('id');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->unsignedBigInteger('series');
            $table->string('token');
            $table->timestamp('date')->useCurrentOnUpdate()->useCurrent()->index('date');
            $table->timestamp('lastactive')->useCurrent();

            $table->unique(['id', 'series', 'token'], 'id_3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
