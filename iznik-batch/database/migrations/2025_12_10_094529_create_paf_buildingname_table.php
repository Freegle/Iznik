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
        if (Schema::hasTable('paf_buildingname')) {
            return;
        }

        Schema::create('paf_buildingname', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('buildingname', 50)->unique('buildingname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paf_buildingname');
    }
};
