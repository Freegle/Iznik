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
        if (Schema::hasTable('stroll_nights')) {
            return;
        }

        Schema::create('stroll_nights', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('date');
            $table->decimal('lat', 10, 4);
            $table->decimal('lng', 10, 4);
            $table->string('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stroll_nights');
    }
};
