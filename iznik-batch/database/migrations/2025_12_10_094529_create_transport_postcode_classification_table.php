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
        if (Schema::hasTable('transport_postcode_classification')) {
            return;
        }

        Schema::create('transport_postcode_classification', function (Blueprint $table) {
            $table->comment('Postcode to Rural-Urban Classification and Region mapping from ONSPD');
            $table->bigIncrements('id');
            $table->string('postcode', 8)->unique('postcode')->comment('Postcode without spaces, e.g. AB101XG');
            $table->string('postcode_space', 9)->nullable()->comment('Original postcode with space, e.g. AB10 1XG');
            $table->string('ru_category', 2)->nullable()->index('ru_category')->comment('Rural-Urban Classification 2011: A1, B1, C1, C2, D1, D2, E1, E2, F1, F2');
            $table->string('region_code', 9)->nullable()->index('region_code')->comment('ONS Region code, e.g. E12000001');
            $table->string('region_name', 50)->nullable()->comment('Region name for reference');
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lng', 10, 6)->nullable();
            $table->timestamp('imported_at')->useCurrent();
            $table->timestamp('last_seen')->useCurrentOnUpdate()->useCurrent()->index('last_seen');

            $table->index(['lat', 'lng'], 'lat_lng');
            $table->index(['ru_category', 'region_code'], 'ru_region');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport_postcode_classification');
    }
};
