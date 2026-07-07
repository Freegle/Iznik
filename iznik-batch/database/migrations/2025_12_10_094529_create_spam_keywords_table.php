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
        if (Schema::hasTable('spam_keywords')) {
            return;
        }

        Schema::create('spam_keywords', function (Blueprint $table) {
            $table->comment('Keywords often used by spammers');
            $table->bigIncrements('id');
            $table->string('word', 80);
            $table->text('exclude')->nullable();
            $table->enum('action', ['Review', 'Spam', 'Whitelist'])->default('Review');
            $table->enum('type', ['Literal', 'Regex'])->default('Literal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spam_keywords');
    }
};
