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
        Schema::table('users_comments', function (Blueprint $table) {
            $table->foreign(['userid'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['byuserid'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['groupid'])->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users_comments', function (Blueprint $table) {
        });
    }
};
