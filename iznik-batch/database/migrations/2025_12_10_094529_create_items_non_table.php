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
        if (Schema::hasTable('items_non')) {
            return;
        }

        Schema::create('items_non', function (Blueprint $table) {
            $table->comment('Not considered items by us, but by image recognition');
            $table->bigIncrements('id');
            $table->string('name')->unique('name');
            $table->integer('popularity')->default(1);
            $table->timestamp('updated')->useCurrentOnUpdate()->useCurrent();
            $table->string('lastexample')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items_non');
    }
};
