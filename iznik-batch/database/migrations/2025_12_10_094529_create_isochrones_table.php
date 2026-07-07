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
        if (Schema::hasTable('isochrones')) {
            return;
        }

        Schema::create('isochrones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('locationid')->nullable()->index('locationid');
            $table->enum('transport', ['Walk', 'Cycle', 'Drive'])->nullable();
            $table->integer('minutes');
            $table->enum('source', ['Mapbox', 'OSM', 'Valhalla', 'GraphHopper', 'ORS'])->nullable()->default('Mapbox')->comment('Isochrone data source');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->geometry('polygon');

            $table->index(['locationid', 'transport', 'minutes', 'source'], 'locationid_2');
            $table->unique(['locationid', 'transport', 'minutes', 'source'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('isochrones');
    }
};
