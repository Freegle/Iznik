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
        if (Schema::hasTable('returnpath_seedlist')) {
            return;
        }

        Schema::create('returnpath_seedlist', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->string('email', 80)->unique('email');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->enum('type', ['ReturnPath', 'Litmus', 'Freegle'])->default('ReturnPath');
            $table->boolean('active')->default(true);
            $table->boolean('oneshot')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('returnpath_seedlist');
    }
};
