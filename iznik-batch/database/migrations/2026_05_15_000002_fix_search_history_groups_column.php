<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_history', function (Blueprint $table) {
            $table->text('groups')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('search_history', function (Blueprint $table) {
            $table->integer('groups')->nullable()->change();
        });
    }
};
