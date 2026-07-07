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
        if (Schema::hasTable('engage_mails')) {
            return;
        }

        Schema::create('engage_mails', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('engagement', ['UT', 'New', 'Occasional', 'Frequent', 'Obsessed', 'Inactive', 'AtRisk', 'Dormant'])->index('engagement');
            $table->string('template', 80);
            $table->string('subject');
            $table->text('text');
            $table->bigInteger('shown')->default(0);
            $table->bigInteger('action')->default(0);
            $table->decimal('rate', 10)->default(0);
            $table->boolean('suggest')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('engage_mails');
    }
};
