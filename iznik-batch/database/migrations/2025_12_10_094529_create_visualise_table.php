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
        if (Schema::hasTable('visualise')) {
            return;
        }

        Schema::create('visualise', function (Blueprint $table) {
            $table->comment('Data to allow us to visualise flows of items to people');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->unique('msgid_2');
            $table->unsignedBigInteger('attid')->index('attid');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->unsignedBigInteger('fromuser')->index('fromuser');
            $table->unsignedBigInteger('touser')->index('touser');
            $table->decimal('fromlat', 10, 6);
            $table->decimal('fromlng', 10, 6);
            $table->decimal('tolat', 10, 6);
            $table->decimal('tolng', 10, 6);
            $table->integer('distance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visualise');
    }
};
