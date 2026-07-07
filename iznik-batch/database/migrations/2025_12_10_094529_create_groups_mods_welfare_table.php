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
        if (Schema::hasTable('groups_mods_welfare')) {
            return;
        }

        Schema::create('groups_mods_welfare', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->unsignedBigInteger('modid')->index('modid');
            $table->enum('state', ['Inactive', 'Ignore'])->default('Inactive');
            $table->timestamp('warnedat')->useCurrent();

            $table->unique(['groupid', 'modid'], 'groupid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups_mods_welfare');
    }
};
