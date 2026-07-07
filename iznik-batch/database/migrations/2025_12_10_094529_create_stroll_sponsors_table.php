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
        if (Schema::hasTable('stroll_sponsors')) {
            return;
        }

        Schema::create('stroll_sponsors', function (Blueprint $table) {
            $table->comment('Edward\'s 2019 stroll; can delete after');
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->decimal('amount', 10, 4)->default(0);
            $table->timestamp('timestamp')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stroll_sponsors');
    }
};
