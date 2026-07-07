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
        if (Schema::hasTable('items')) {
            return;
        }

        Schema::create('items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique('name');
            $table->integer('popularity')->default(0)->index('popularity');
            $table->decimal('weight', 10)->nullable();
            $table->timestamp('updated')->useCurrent();
            $table->tinyInteger('suggestfromphoto')->default(1)->comment('We can exclude from image recognition');
            $table->boolean('suggestfromtypeahead')->default(true)->comment('We can exclude from typeahead');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
