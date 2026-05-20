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
        if (Schema::hasTable('search_history')) {
            return;
        }

        Schema::create('search_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->nullable();
            $table->timestamp('date')->useCurrent()->index('date');
            $table->string('term', 80);
            $table->unsignedBigInteger('locationid')->nullable()->index('locationid');
            $table->text('groups')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_history');
    }
};
