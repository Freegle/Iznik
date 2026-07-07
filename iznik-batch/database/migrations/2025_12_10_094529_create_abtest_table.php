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
        if (Schema::hasTable('abtest')) {
            return;
        }

        Schema::create('abtest', function (Blueprint $table) {
            $table->comment('For testing site changes to see which work');
            $table->bigIncrements('id');
            $table->string('uid', 50);
            $table->string('variant', 50);
            $table->unsignedBigInteger('shown');
            $table->unsignedBigInteger('action');
            $table->decimal('rate', 10);
            $table->boolean('suggest')->default(true);
            $table->timestamp('timestamp')->useCurrentOnUpdate()->nullable();

            $table->unique(['uid', 'variant'], 'uid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('abtest');
    }
};
