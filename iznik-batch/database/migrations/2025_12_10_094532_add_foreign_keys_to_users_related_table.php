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
        Schema::table('users_related', function (Blueprint $table) {
            $table->foreign(['user1'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user2'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_related', function (Blueprint $table) {
        });
    }
};
