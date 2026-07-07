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
        if (Schema::hasTable('spam_countries')) {
            return;
        }

        Schema::create('spam_countries', function (Blueprint $table) {
            $table->bigIncrements('id')->unique('id');
            $table->string('country', 80)->index('country')->comment('A country we want to block');

            $table->primary(['id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spam_countries');
    }
};
