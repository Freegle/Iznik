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
        if (Schema::hasTable('messages_related')) {
            return;
        }

        Schema::create('messages_related', function (Blueprint $table) {
            $table->comment('Messages which are related to each other');
            $table->unsignedBigInteger('id1')->index('id1');
            $table->unsignedBigInteger('id2')->index('id2');

            $table->unique(['id1', 'id2'], 'id1_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_related');
    }
};
