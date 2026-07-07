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
        if (Schema::hasTable('bounces')) {
            return;
        }

        Schema::create('bounces', function (Blueprint $table) {
            $table->comment('Bounce messages received by email');
            $table->bigIncrements('id');
            $table->string('to', 80);
            $table->longText('msg');
            $table->timestamp('date')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bounces');
    }
};
