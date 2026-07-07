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
        if (Schema::hasTable('locations_spatial')) {
            return;
        }

        Schema::create('locations_spatial', function (Blueprint $table) {
            $table->unsignedBigInteger('locationid')->unique('locationid');
            $table->geography('geometry', null, 3857);

            $table->spatialIndex(['geometry'], 'geometry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations_spatial');
    }
};
