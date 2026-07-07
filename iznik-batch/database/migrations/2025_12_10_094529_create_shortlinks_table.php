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
        if (Schema::hasTable('shortlinks')) {
            return;
        }

        Schema::create('shortlinks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 80)->index('name');
            $table->enum('type', ['Group', 'Other'])->default('Other');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->string('url')->nullable();
            $table->bigInteger('clicks')->default(0);
            $table->timestamp('created')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shortlinks');
    }
};
