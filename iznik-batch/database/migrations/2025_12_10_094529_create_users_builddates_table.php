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
        if (Schema::hasTable('users_builddates')) {
            return;
        }

        Schema::create('users_builddates', function (Blueprint $table) {
            $table->comment('Used to spot old clients');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->unique('userid');
            $table->timestamp('timestamp')->useCurrent();
            $table->string('webversion', 32)->nullable();
            $table->string('appversion', 32)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_builddates');
    }
};
