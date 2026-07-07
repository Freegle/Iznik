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
        if (Schema::hasTable('locations_grids_touches')) {
            return;
        }

        Schema::create('locations_grids_touches', function (Blueprint $table) {
            $table->comment('A record of which grid squares touch others');
            $table->unsignedBigInteger('gridid');
            $table->unsignedBigInteger('touches')->index('touches');

            $table->unique(['gridid', 'touches'], 'gridid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations_grids_touches');
    }
};
