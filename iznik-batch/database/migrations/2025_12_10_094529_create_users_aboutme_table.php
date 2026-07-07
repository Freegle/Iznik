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
        if (Schema::hasTable('users_aboutme')) {
            return;
        }

        Schema::create('users_aboutme', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->unsignedBigInteger('userid')->index('userid');
            $table->timestamp('timestamp')->useCurrent();
            $table->text('text')->nullable();

            $table->index(['userid', 'timestamp'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_aboutme');
    }
};
