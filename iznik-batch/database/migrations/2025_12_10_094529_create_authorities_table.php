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
        if (Schema::hasTable('authorities')) {
            return;
        }

        Schema::create('authorities', function (Blueprint $table) {
            $table->comment('Counties and Unitary Authorities.  May be multigeometries');
            $table->bigIncrements('id');
            $table->string('name')->index('name_2');
            $table->geography('polygon', null, 3857);
            $table->string('area_code', 10)->nullable();
            $table->geometry('simplified')->nullable();

            $table->unique(['name', 'area_code'], 'name');
            $table->spatialIndex(['polygon'], 'polygon');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authorities');
    }
};
