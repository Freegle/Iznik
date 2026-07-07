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
        if (Schema::hasTable('stats_outcomes')) {
            return;
        }

        Schema::create('stats_outcomes', function (Blueprint $table) {
            $table->comment('For efficient stats calculations, refreshed via cron');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->integer('count');
            $table->string('date', 7);

            $table->unique(['groupid', 'date'], 'groupid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stats_outcomes');
    }
};
