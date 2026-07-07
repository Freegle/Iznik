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
        Schema::table('items_index', function (Blueprint $table) {
            $table->foreign(['itemid'])->references(['id'])->on('items')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['wordid'])->references(['id'])->on('words')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items_index', function (Blueprint $table) {
        });
    }
};
