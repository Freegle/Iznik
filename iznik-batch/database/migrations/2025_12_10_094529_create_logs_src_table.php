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
        if (Schema::hasTable('logs_src')) {
            return;
        }

        Schema::create('logs_src', function (Blueprint $table) {
            $table->comment('Record which mails we sent generated website traffic');
            $table->bigIncrements('id');
            $table->string('src', 40)->index('src');
            $table->timestamp('date')->useCurrent()->index('date');
            $table->unsignedBigInteger('userid')->nullable();
            $table->string('session')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_src');
    }
};
