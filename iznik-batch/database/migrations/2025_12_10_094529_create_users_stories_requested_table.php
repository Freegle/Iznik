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
        if (Schema::hasTable('users_stories_requested')) {
            return;
        }

        Schema::create('users_stories_requested', function (Blueprint $table) {
            $table->bigInteger('id', true)->unique('id');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->timestamp('date')->useCurrent();

            $table->primary(['id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_stories_requested');
    }
};
