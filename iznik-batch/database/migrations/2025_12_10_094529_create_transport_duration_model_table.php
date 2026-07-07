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
        if (Schema::hasTable('transport_duration_model')) {
            return;
        }

        Schema::create('transport_duration_model', function (Blueprint $table) {
            $table->comment('Transport speed and duration parameters by area type and region');
            $table->increments('id');
            $table->string('ru_category', 2)->index('ru_category')->comment('A1, B1, C1, C2, D1, D2, E1, E2, F1, F2');
            $table->string('region_code', 9)->comment('E12000001, etc.');
            $table->string('ru_description', 100)->nullable()->comment('Human-readable description');
            $table->decimal('walk_speed_mph', 4)->default(3)->comment('Average walking speed in mph');
            $table->decimal('cycle_speed_mph', 4)->default(10)->comment('Average cycling speed in mph');
            $table->decimal('drive_speed_mph', 4)->default(20)->comment('Average driving speed in mph');
            $table->integer('walk_base_mins')->default(18)->comment('NTS 2024 average walk trip duration');
            $table->integer('cycle_base_mins')->default(24)->comment('NTS 2024 average cycle trip duration');
            $table->integer('drive_base_mins')->default(22)->comment('NTS 2024 average drive trip duration');
            $table->decimal('time_adjustment_factor', 4)->default(1)->comment('Rural adjustment factor (1.0=urban, 1.33=rural)');
            $table->decimal('avg_walk_distance_miles', 5)->nullable()->comment('Average walk trip distance from NTS');
            $table->decimal('avg_cycle_distance_miles', 5)->nullable()->comment('Average cycle trip distance from NTS');
            $table->decimal('avg_drive_distance_miles', 5)->nullable()->comment('Average drive trip distance from NTS');
            $table->string('data_source', 100)->nullable()->default('NTS2024')->comment('Source of the data');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->timestamp('last_seen')->useCurrentOnUpdate()->useCurrent()->index('last_seen');

            $table->unique(['ru_category', 'region_code'], 'ru_region');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport_duration_model');
    }
};
