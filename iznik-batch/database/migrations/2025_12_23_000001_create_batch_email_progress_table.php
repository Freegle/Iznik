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
        if (Schema::hasTable('batch_email_progress')) {
            return;
        }

        Schema::create('batch_email_progress', function (Blueprint $table) {
            $table->id();
            $table->string('job_type', 50)->unique()
                ->comment('Type of batch job: welcome, digest, donation_ask, etc.');
            $table->unsignedBigInteger('last_processed_id')->nullable()
                ->comment('Last user/message ID successfully processed');
            $table->timestamp('last_processed_at')->nullable()
                ->comment('When last batch completed');
            $table->timestamp('started_at')->nullable()
                ->comment('When current batch started');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batch_email_progress');
    }
};
