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
        if (Schema::hasTable('aviva_votes')) {
            return;
        }

        Schema::create('aviva_votes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('timestamp')->useCurrent()->index('timestamp');
            $table->string('project', 20)->unique('project');
            $table->text('name');
            $table->integer('votes')->index('votes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aviva_votes');
    }
};
