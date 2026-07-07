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
        if (Schema::hasTable('locations_grids')) {
            return;
        }

        Schema::create('locations_grids', function (Blueprint $table) {
            $table->comment('Used to map lat/lng to gridid for location searches');
            $table->bigIncrements('id');
            $table->decimal('swlat', 10, 6);
            $table->decimal('swlng', 10, 6);
            $table->decimal('nelat', 10, 6);
            $table->decimal('nelng', 10, 6);
            $table->geometry('box');

            $table->unique(['swlat', 'swlng', 'nelat', 'nelng'], 'swlat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations_grids');
    }
};
