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
        if (Schema::hasTable('transport_mode_probabilities')) {
            return;
        }

        Schema::create('transport_mode_probabilities', function (Blueprint $table) {
            $table->comment('Transport mode choice probabilities by area type from NTS');
            $table->increments('id');
            $table->string('ru_category', 2)->index('ru_category');
            $table->string('region_code', 9);
            $table->decimal('walk_pct', 5)->nullable()->default(0)->comment('Percentage of trips by walking');
            $table->decimal('cycle_pct', 5)->nullable()->default(0)->comment('Percentage of trips by cycling');
            $table->decimal('drive_pct', 5)->nullable()->default(0)->comment('Percentage of trips by driving');
            $table->decimal('bus_pct', 5)->nullable()->default(0)->comment('Percentage of trips by bus');
            $table->decimal('rail_pct', 5)->nullable()->default(0)->comment('Percentage of trips by rail');
            $table->decimal('other_pct', 5)->nullable()->default(0)->comment('Percentage of trips by other modes');
            $table->integer('avg_trips_per_year')->nullable()->comment('Average trips per person per year');
            $table->string('data_source', 100)->nullable()->default('NTS2024')->comment('Source: NTS9903');
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
        Schema::dropIfExists('transport_mode_probabilities');
    }
};
