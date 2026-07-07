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
        if (Schema::hasTable('paf_posttown')) {
            return;
        }

        Schema::create('paf_posttown', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('posttown', 30)->unique('posttown');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paf_posttown');
    }
};
