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
        if (Schema::hasTable('paf_organisationname')) {
            return;
        }

        Schema::create('paf_organisationname', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('organisationname', 60)->unique('organisationname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paf_organisationname');
    }
};
