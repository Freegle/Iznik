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
        if (Schema::hasTable('paf_subbuildingname')) {
            return;
        }

        Schema::create('paf_subbuildingname', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('subbuildingname', 60)->unique('subbuildingname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paf_subbuildingname');
    }
};
