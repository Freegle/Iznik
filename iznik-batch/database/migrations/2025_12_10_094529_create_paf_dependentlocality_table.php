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
        if (Schema::hasTable('paf_dependentlocality')) {
            return;
        }

        Schema::create('paf_dependentlocality', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('dependentlocality', 35)->unique('dependentlocality');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paf_dependentlocality');
    }
};
