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
        if (Schema::hasTable('ebay_favourites')) {
            return;
        }

        Schema::create('ebay_favourites', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->timestamp('timestamp')->useCurrent();
            $table->integer('count');
            $table->integer('rival')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ebay_favourites');
    }
};
