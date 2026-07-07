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
        if (Schema::hasTable('locations')) {
            return;
        }

        Schema::create('locations', function (Blueprint $table) {
            $table->comment('Location data, the bulk derived from OSM');
            $table->bigIncrements('id');
            $table->string('osm_id', 50)->nullable()->index('osm_id');
            $table->string('name')->index('name');
            $table->enum('type', ['Road', 'Polygon', 'Line', 'Point', 'Postcode']);
            $table->boolean('osm_place')->nullable()->default(false);
            $table->geometry('geometry')->nullable();
            $table->geometry('ourgeometry')->nullable()->comment('geometry comes from OSM; this comes from us');
            $table->unsignedBigInteger('gridid')->nullable();
            $table->unsignedBigInteger('postcodeid')->nullable()->index('postcodeid');
            $table->unsignedBigInteger('areaid')->nullable()->index('areaid');
            $table->string('canon')->nullable()->index('canon');
            $table->unsignedBigInteger('popularity')->nullable()->default(0);
            $table->boolean('osm_amenity')->default(false)->comment('For OSM locations, whether this is an amenity');
            $table->boolean('osm_shop')->default(false)->comment('For OSM locations, whether this is a shop');
            $table->decimal('maxdimension', 10, 6)->nullable()->comment('GetMaxDimension on geomtry');
            $table->decimal('lat', 10, 6)->nullable()->index('lat');
            $table->decimal('lng', 10, 6)->nullable()->index('lng');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');
            $table->unsignedBigInteger('newareaid')->nullable()->index('newareaid');

            $table->index(['gridid', 'osm_place'], 'gridid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
