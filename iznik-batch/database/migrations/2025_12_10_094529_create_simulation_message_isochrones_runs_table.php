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
        if (Schema::hasTable('simulation_message_isochrones_runs')) {
            return;
        }

        Schema::create('simulation_message_isochrones_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('created')->useCurrent()->index('created');
            $table->timestamp('completed')->nullable();
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->nullable()->default('pending')->index('status');
            $table->json('parameters')->comment('Simulation parameters');
            $table->json('filters')->comment('Date range, groupid filter');
            $table->integer('message_count')->nullable()->default(0);
            $table->json('metrics')->nullable()->comment('Aggregate metrics across all messages');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulation_message_isochrones_runs');
    }
};
