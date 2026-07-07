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
        Schema::table('noticeboards_checks', function (Blueprint $table) {
            $table->foreign(['userid'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['noticeboardid'])->references(['id'])->on('noticeboards')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('noticeboards_checks', function (Blueprint $table) {
        });
    }
};
