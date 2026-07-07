<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cron_job_status')) {
            return;
        }

        Schema::create('cron_job_status', function (Blueprint $table) {
            $table->id();
            $table->string('command', 255)->unique();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->integer('last_exit_code')->nullable();
            $table->mediumText('last_output')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_job_status');
    }
};
