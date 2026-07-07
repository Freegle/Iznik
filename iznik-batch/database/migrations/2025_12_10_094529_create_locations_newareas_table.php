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
        if (Schema::hasTable('locations_newareas')) {
            return;
        }

        Schema::create('locations_newareas', function (Blueprint $table) {
            $table->unsignedBigInteger('locationid');
            $table->unsignedBigInteger('areaid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations_newareas');
    }
};
