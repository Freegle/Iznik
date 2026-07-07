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
        if (Schema::hasTable('paf_doubledependentlocality')) {
            return;
        }

        Schema::create('paf_doubledependentlocality', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('doubledependentlocality', 35)->unique('doubledependentlocality');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paf_doubledependentlocality');
    }
};
