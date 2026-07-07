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
        if (Schema::hasTable('paf_thoroughfaredescriptor')) {
            return;
        }

        Schema::create('paf_thoroughfaredescriptor', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('thoroughfaredescriptor', 80)->unique('thoroughfaredescriptor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paf_thoroughfaredescriptor');
    }
};
