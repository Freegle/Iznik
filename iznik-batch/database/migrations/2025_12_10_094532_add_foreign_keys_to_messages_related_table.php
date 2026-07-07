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
        Schema::table('messages_related', function (Blueprint $table) {
            $table->foreign(['id1'])->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['id2'])->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages_related', function (Blueprint $table) {
        });
    }
};
