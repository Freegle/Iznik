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
        Schema::table('alerts_tracking', function (Blueprint $table) {
            $table->foreign(['emailid'])->references(['id'])->on('users_emails')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['alertid'])->references(['id'])->on('alerts')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['userid'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['groupid'])->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alerts_tracking', function (Blueprint $table) {
        });
    }
};
