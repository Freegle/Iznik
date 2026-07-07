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
        if (Schema::hasTable('domains_common')) {
            return;
        }

        Schema::create('domains_common', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->string('domain')->unique('domain');
            $table->integer('count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains_common');
    }
};
