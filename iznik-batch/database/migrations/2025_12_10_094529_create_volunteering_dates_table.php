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
        if (Schema::hasTable('volunteering_dates')) {
            return;
        }

        Schema::create('volunteering_dates', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->unsignedBigInteger('volunteeringid')->index('eventid');
            $table->timestamp('start')->nullable()->index('start');
            $table->timestamp('end')->nullable()->index('end');
            $table->timestamp('applyby')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('volunteering_dates');
    }
};
