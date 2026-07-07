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
        if (Schema::hasTable('partners_keys')) {
            return;
        }

        Schema::create('partners_keys', function (Blueprint $table) {
            $table->comment('For site-to-site integration');
            $table->bigIncrements('id');
            $table->string('partner');
            $table->string('key');
            $table->string('domain')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partners_keys');
    }
};
