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
        Schema::table('users_expected', function (Blueprint $table) {
            $table->foreign(['expecter'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['expectee'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['chatmsgid'])->references(['id'])->on('chat_messages')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_expected', function (Blueprint $table) {
        });
    }
};
