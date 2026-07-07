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
        if (Schema::hasTable('newsletters_articles')) {
            return;
        }

        Schema::create('newsletters_articles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('newsletterid')->index('mailid');
            $table->enum('type', ['Header', 'Article'])->default('Article');
            $table->integer('position');
            $table->text('html');
            $table->unsignedBigInteger('photoid')->nullable()->index('photo');
            $table->integer('width')->default(250);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsletters_articles');
    }
};
