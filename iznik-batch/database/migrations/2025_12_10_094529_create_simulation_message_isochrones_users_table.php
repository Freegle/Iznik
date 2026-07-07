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
        if (Schema::hasTable('simulation_message_isochrones_users')) {
            return;
        }

        Schema::create('simulation_message_isochrones_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sim_msgid')->index('sim_msgid');
            $table->string('user_hash', 64)->comment('Anonymized user ID');
            $table->decimal('lat', 10, 6)->comment('Blurred location from users_approxlocs');
            $table->decimal('lng', 10, 6);
            $table->boolean('in_group')->nullable()->default(true);
            $table->boolean('replied')->nullable()->default(false)->index('replied');
            $table->timestamp('reply_time')->nullable();
            $table->integer('reply_minutes')->nullable()->comment('Minutes after message arrival');
            $table->decimal('distance_km', 10)->nullable()->comment('Distance from message location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulation_message_isochrones_users');
    }
};
