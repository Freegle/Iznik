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
        if (Schema::hasTable('modnotifs')) {
            return;
        }

        Schema::create('modnotifs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->unique('userid');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->text('data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modnotifs');
    }
};
