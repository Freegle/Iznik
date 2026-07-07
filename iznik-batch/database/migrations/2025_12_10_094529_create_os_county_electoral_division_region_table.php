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
        if (Schema::hasTable('os_county_electoral_division_region')) {
            return;
        }

        Schema::create('os_county_electoral_division_region', function (Blueprint $table) {
            $table->integer('OGR_FID', true)->unique('ogr_fid');
            $table->geometry('SHAPE');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('os_county_electoral_division_region');
    }
};
