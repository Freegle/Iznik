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
        Schema::table('messages_spatial', function (Blueprint $table) {
            $table->foreign(['msgid'])->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['groupid'])->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages_spatial', function (Blueprint $table) {
        });
    }
};
