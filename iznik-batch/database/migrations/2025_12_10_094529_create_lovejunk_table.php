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
        if (Schema::hasTable('lovejunk')) {
            return;
        }

        Schema::create('lovejunk', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->unsignedBigInteger('msgid')->unique('msgid');
            $table->boolean('success');
            $table->text('status');
            $table->timestamp('deleted')->nullable();
            $table->text('deletestatus')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lovejunk');
    }
};
