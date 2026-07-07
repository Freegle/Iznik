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
        if (Schema::hasTable('logs_errors')) {
            return;
        }

        Schema::create('logs_errors', function (Blueprint $table) {
            $table->comment('Errors from client');
            $table->bigInteger('id', true);
            $table->timestamp('date')->useCurrent();
            $table->enum('type', ['Exception'])->nullable();
            $table->bigInteger('userid')->nullable()->index('userid');
            $table->text('text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_errors');
    }
};
