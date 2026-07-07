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
        if (Schema::hasTable('simulation_message_isochrones_sessions')) {
            return;
        }

        Schema::create('simulation_message_isochrones_sessions', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->unsignedBigInteger('runid')->index('simulation_message_isochrones_sessions_ibfk_1');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->integer('current_index')->nullable()->default(0);
            $table->timestamp('created')->nullable()->useCurrent();
            $table->timestamp('expires')->nullable()->index('expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulation_message_isochrones_sessions');
    }
};
