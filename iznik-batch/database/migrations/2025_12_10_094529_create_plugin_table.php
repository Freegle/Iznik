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
        if (Schema::hasTable('plugin')) {
            return;
        }

        Schema::create('plugin', function (Blueprint $table) {
            $table->comment('Outstanding work required to be performed by the plugin');
            $table->bigIncrements('id');
            $table->timestamp('added')->useCurrent();
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->mediumText('data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plugin');
    }
};
