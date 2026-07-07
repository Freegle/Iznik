<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('housekeeper_tasks')) {
            return;
        }

        Schema::create('housekeeper_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('task_key', 100)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('interval_hours')->default(168);
            $table->boolean('enabled')->default(false);
            $table->boolean('placeholder')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 50)->nullable();
            $table->text('last_summary')->nullable();
            $table->mediumText('last_log')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeper_tasks');
    }
};
