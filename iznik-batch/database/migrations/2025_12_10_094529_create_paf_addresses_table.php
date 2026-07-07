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
        if (Schema::hasTable('paf_addresses')) {
            return;
        }

        Schema::create('paf_addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('postcodeid')->nullable()->index('postcodeid');
            $table->unsignedBigInteger('posttownid')->nullable();
            $table->unsignedBigInteger('dependentlocalityid')->nullable();
            $table->unsignedBigInteger('doubledependentlocalityid')->nullable();
            $table->unsignedBigInteger('thoroughfaredescriptorid')->nullable();
            $table->unsignedBigInteger('dependentthoroughfaredescriptorid')->nullable();
            $table->integer('buildingnumber')->nullable();
            $table->unsignedBigInteger('buildingnameid')->nullable();
            $table->unsignedBigInteger('subbuildingnameid')->nullable();
            $table->unsignedBigInteger('poboxid')->nullable();
            $table->unsignedBigInteger('departmentnameid')->nullable();
            $table->unsignedBigInteger('organisationnameid')->nullable();
            $table->unsignedBigInteger('udprn')->nullable()->unique('udprn');
            $table->char('postcodetype', 1)->nullable();
            $table->char('suorganisationindicator', 1)->nullable();
            $table->string('deliverypointsuffix', 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paf_addresses');
    }
};
