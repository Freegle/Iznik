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
        if (Schema::hasTable('polls')) {
            return;
        }

        Schema::create('polls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('date')->useCurrent();
            $table->string('name', 80);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->text('template');
            $table->enum('logintype', ['Facebook', 'Google', 'Yahoo', 'Native'])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('polls');
    }
};
