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
        Schema::table('groups', function (Blueprint $table) {
            $table->foreign(['profile'])->references(['id'])->on('groups_images')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['cover'])->references(['id'])->on('groups')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['defaultlocation'])->references(['id'])->on('locations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['affiliationconfirmedby'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
        });
    }
};
