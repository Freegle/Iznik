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
        if (Schema::hasTable('mod_bulkops_run')) {
            return;
        }

        Schema::create('mod_bulkops_run', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('bulkopid')->index('bulkopid');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->timestamp('runstarted')->nullable();
            $table->timestamp('runfinished')->nullable();

            $table->unique(['bulkopid', 'groupid'], 'bulkopid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mod_bulkops_run');
    }
};
