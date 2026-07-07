<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the background_tasks table for Go-to-batch async communication.
 *
 * Go API handlers insert rows when they need async side effects (emails,
 * push notifications) that should be processed by the batch container.
 * A Laravel command polls this table and dispatches each task to the
 * appropriate service.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('background_tasks')) {
            return;
        }

        Schema::create('background_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('task_type', 50)->index();
            $table->json('data');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            $table->index(['processed_at', 'created_at'], 'idx_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_tasks');
    }
};
