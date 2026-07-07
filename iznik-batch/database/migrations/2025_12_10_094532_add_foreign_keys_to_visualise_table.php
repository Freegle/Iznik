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
        Schema::table('visualise', function (Blueprint $table) {
            $table->foreign(['attid'])->references(['id'])->on('messages_attachments')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['msgid'])->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['fromuser'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['touser'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visualise', function (Blueprint $table) {
        });
    }
};
