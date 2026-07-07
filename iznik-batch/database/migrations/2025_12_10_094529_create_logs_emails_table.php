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
        if (Schema::hasTable('logs_emails')) {
            return;
        }

        Schema::create('logs_emails', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');
            $table->string('eximid', 12)->nullable()->index('timestamp_2');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->string('from')->nullable();
            $table->string('to')->nullable();
            $table->string('messageid')->nullable();
            $table->string('subject')->nullable();
            $table->string('status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_emails');
    }
};
