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
        if (Schema::hasTable('users_banned')) {
            return;
        }

        Schema::create('users_banned', function (Blueprint $table) {
            $table->unsignedBigInteger('userid')->index('userid');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->timestamp('date')->useCurrentOnUpdate()->useCurrent()->index('date');
            $table->unsignedBigInteger('byuser')->nullable()->index('byuser');

            $table->unique(['userid', 'groupid'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_banned');
    }
};
