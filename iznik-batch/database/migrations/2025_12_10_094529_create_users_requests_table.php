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
        if (Schema::hasTable('users_requests')) {
            return;
        }

        Schema::create('users_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->enum('type', ['BusinessCards']);
            $table->timestamp('date')->useCurrent();
            $table->timestamp('completed')->nullable()->index('completed');
            $table->unsignedBigInteger('completedby')->nullable()->index('completedby');
            $table->unsignedBigInteger('addressid')->nullable()->index('addressid');
            $table->string('to', 80)->nullable();
            $table->timestamp('notifiedmods')->nullable();
            $table->boolean('paid')->default(false);
            $table->integer('amount')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_requests');
    }
};
