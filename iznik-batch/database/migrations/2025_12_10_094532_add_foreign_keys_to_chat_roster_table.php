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
        Schema::table('chat_roster', function (Blueprint $table) {
            $table->foreign(['chatid'])->references(['id'])->on('chat_rooms')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_roster', function (Blueprint $table) {
        });
    }
};
