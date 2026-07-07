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
        if (Schema::hasTable('items_index')) {
            return;
        }

        Schema::create('items_index', function (Blueprint $table) {
            $table->unsignedBigInteger('itemid')->index('itemid_2');
            $table->unsignedBigInteger('wordid');
            $table->integer('popularity')->default(0);
            $table->unsignedBigInteger('categoryid')->nullable();

            $table->unique(['itemid', 'wordid'], 'itemid');
            $table->index(['wordid', 'popularity'], 'wordid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items_index');
    }
};
