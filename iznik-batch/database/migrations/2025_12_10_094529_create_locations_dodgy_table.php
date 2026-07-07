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
        if (Schema::hasTable('locations_dodgy')) {
            return;
        }

        Schema::create('locations_dodgy', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('locationid')->nullable()->index('locationid');
            $table->decimal('lat', 10, 6)->index('lat');
            $table->decimal('lng', 10, 6)->index('lng');
            $table->unsignedBigInteger('oldlocationid')->nullable()->index('oldlocationid');
            $table->unsignedBigInteger('newlocationid')->nullable()->index('newlocationid');

            $table->index(['lat', 'lng'], 'lat_2');
            $table->index(['lat', 'lng'], 'lat_3');
            $table->index(['locationid'], 'locationid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations_dodgy');
    }
};
