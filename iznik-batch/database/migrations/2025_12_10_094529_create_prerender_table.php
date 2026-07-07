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
        if (Schema::hasTable('prerender')) {
            return;
        }

        Schema::create('prerender', function (Blueprint $table) {
            $table->comment('Saved copies of HTML for logged out view of pages');
            $table->bigIncrements('id');
            $table->string('url', 128)->unique('url');
            $table->longText('html')->nullable();
            $table->longText('head')->nullable();
            $table->timestamp('retrieved')->useCurrentOnUpdate()->useCurrent();
            $table->integer('timeout')->default(60)->comment('In minutes');
            $table->string('title')->nullable();
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prerender');
    }
};
