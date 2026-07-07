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
        Schema::table('newsletters_articles', function (Blueprint $table) {
            $table->foreign(['newsletterid'])->references(['id'])->on('newsletters')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['photoid'])->references(['id'])->on('newsletters_images')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('newsletters_articles', function (Blueprint $table) {
        });
    }
};
