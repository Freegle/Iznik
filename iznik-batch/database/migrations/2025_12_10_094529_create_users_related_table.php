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
        if (Schema::hasTable('users_related')) {
            return;
        }

        Schema::create('users_related', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user1')->index('user1');
            $table->unsignedBigInteger('user2')->index('user2');
            $table->timestamp('timestamp')->useCurrent();
            $table->boolean('notified')->default(false)->index('notified');
            $table->enum('detected', ['Auto', 'UserRequest'])->default('Auto');

            $table->unique(['user1', 'user2'], 'user1_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_related');
    }
};
