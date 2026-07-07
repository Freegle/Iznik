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
        if (Schema::hasTable('worrywords')) {
            return;
        }

        Schema::create('worrywords', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->string('keyword')->unique('keyword');
            $table->string('substance')->nullable();
            $table->enum('type', ['Regulated', 'Reportable', 'Medicine', 'Review', 'Allowed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worrywords');
    }
};
