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
        Schema::table('simulation_message_isochrones_users', function (Blueprint $table) {
            $table->foreign(['sim_msgid'])->references(['id'])->on('simulation_message_isochrones_messages')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('simulation_message_isochrones_users', function (Blueprint $table) {
        });
    }
};
