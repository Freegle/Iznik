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
        if (Schema::hasTable('link_previews')) {
            return;
        }

        Schema::create('link_previews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('url')->unique('url');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->boolean('invalid')->default(false);
            $table->boolean('spam')->default(false);
            $table->timestamp('retrieved')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('link_previews');
    }
};
