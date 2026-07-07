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
        if (Schema::hasTable('users_exports')) {
            return;
        }

        Schema::create('users_exports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->timestamp('requested')->useCurrent();
            $table->timestamp('started')->nullable();
            $table->timestamp('completed')->nullable()->index('completed');
            $table->string('tag', 64)->nullable();
            $table->binary('data')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_exports');
    }
};
