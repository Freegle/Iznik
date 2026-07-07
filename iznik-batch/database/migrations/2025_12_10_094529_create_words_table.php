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
        if (Schema::hasTable('words')) {
            return;
        }

        Schema::create('words', function (Blueprint $table) {
            $table->comment('Unique words for searches');
            $table->bigIncrements('id');
            $table->string('word', 10)->unique('word_2');
            $table->string('firstthree', 3);
            $table->string('soundex', 10);
            $table->bigInteger('popularity')->default(0)->index('popularity')->comment('Negative as DESC index not supported');

            $table->index(['firstthree', 'popularity'], 'firstthree');
            $table->index(['soundex', 'popularity'], 'soundex');
            $table->index(['word', 'popularity'], 'word');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('words');
    }
};
