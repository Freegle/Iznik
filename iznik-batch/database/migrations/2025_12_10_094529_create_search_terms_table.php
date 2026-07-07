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
        if (Schema::hasTable('search_terms')) {
            return;
        }

        Schema::create('search_terms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('term')->unique('term');
            $table->integer('count')->default(0)->index('count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_terms');
    }
};
