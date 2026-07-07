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
        if (Schema::hasTable('simulation_message_isochrones_expansions')) {
            return;
        }

        Schema::create('simulation_message_isochrones_expansions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sim_msgid')->comment('FK to simulation_message_isochrones_messages');
            $table->integer('sequence')->comment('Expansion number (0 = initial)');
            $table->timestamp('timestamp');
            $table->integer('minutes_after_arrival');
            $table->integer('minutes')->comment('Isochrone size in minutes');
            $table->enum('transport', ['walk', 'cycle', 'drive'])->nullable()->default('walk');
            $table->json('isochrone_polygon')->nullable()->comment('Isochrone GeoJSON');
            $table->integer('users_in_isochrone')->nullable()->default(0);
            $table->integer('new_users_reached')->nullable()->default(0);
            $table->integer('replies_at_time')->nullable()->default(0);
            $table->integer('replies_in_isochrone')->nullable()->default(0);

            $table->index(['sim_msgid', 'sequence'], 'sim_msgid_sequence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulation_message_isochrones_expansions');
    }
};
