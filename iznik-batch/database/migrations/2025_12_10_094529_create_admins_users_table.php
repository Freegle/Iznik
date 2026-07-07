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
        if (Schema::hasTable('admins_users')) {
            return;
        }

        Schema::create('admins_users', function (Blueprint $table) {
            $table->comment('Used to prevent dups of related admins');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->unsignedBigInteger('adminid');
            $table->timestamp('timestamp')->useCurrent();

            $table->index(['adminid', 'userid'], 'adminid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins_users');
    }
};
