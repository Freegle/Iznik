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
        if (Schema::hasTable('logs_sql')) {
            return;
        }

        Schema::create('logs_sql', function (Blueprint $table) {
            $table->comment('Log of modification SQL operations');
            $table->bigIncrements('id')->unique('id');
            $table->timestamp('date')->useCurrent()->index('date');
            $table->decimal('duration', 15, 10)->unsigned()->nullable()->default(0)->comment('seconds');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->string('session')->index('session');
            $table->longText('request');
            $table->string('response', 20)->comment('rc:lastInsertId');

            $table->primary(['id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_sql');
    }
};
