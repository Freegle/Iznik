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
        if (Schema::hasTable('mod_bulkops')) {
            return;
        }

        Schema::create('mod_bulkops', function (Blueprint $table) {
            $table->bigIncrements('id')->unique('uniqueid');
            $table->string('title');
            $table->unsignedBigInteger('configid')->nullable()->index('configid');
            $table->enum('set', ['Members']);
            $table->enum('criterion', ['Bouncing', 'BouncingFor', 'WebOnly', 'All'])->nullable();
            $table->integer('runevery')->default(168)->comment('In hours');
            $table->enum('action', ['Unbounce', 'Remove', 'ToGroup', 'ToSpecialNotices'])->nullable();
            $table->integer('bouncingfor')->default(90);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mod_bulkops');
    }
};
