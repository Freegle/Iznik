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
        if (Schema::hasTable('alerts')) {
            return;
        }

        Schema::create('alerts', function (Blueprint $table) {
            $table->comment('Try all means to reach people with these');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('createdby')->nullable()->index('createdby');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->string('from', 80);
            $table->enum('to', ['Users', 'Mods'])->default('Mods');
            $table->timestamp('created')->useCurrent();
            $table->unsignedBigInteger('groupprogress')->default(0)->comment('For alerts to multiple groups');
            $table->timestamp('complete')->nullable();
            $table->text('subject');
            $table->text('text');
            $table->text('html');
            $table->boolean('askclick')->default(false)->comment('Whether to ask them to click to confirm receipt');
            $table->tinyInteger('tryhard')->default(1)->comment('Whether to mail all mods addresses too');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
