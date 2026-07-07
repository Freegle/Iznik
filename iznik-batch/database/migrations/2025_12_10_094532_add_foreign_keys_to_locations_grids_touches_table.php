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
        Schema::table('locations_grids_touches', function (Blueprint $table) {
            $table->foreign(['gridid'])->references(['id'])->on('locations_grids')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['touches'])->references(['id'])->on('locations_grids')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations_grids_touches', function (Blueprint $table) {
        });
    }
};
