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
        if (Schema::hasTable('stroll_route')) {
            return;
        }

        Schema::create('stroll_route', function (Blueprint $table) {
            $table->comment('Edward\'s 2019 stroll; can delete after');
            $table->bigIncrements('id');
            $table->decimal('lat', 10, 4);
            $table->decimal('lng', 10, 4);
            $table->decimal('fromlast', 10, 4);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stroll_route');
    }
};
