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
        if (Schema::hasTable('logs_jobs')) {
            return;
        }

        Schema::create('logs_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('timestamp')->useCurrent()->index('timestamp');
            $table->unsignedBigInteger('jobid')->nullable()->index('jobid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_jobs');
    }
};
