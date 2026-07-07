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
        if (Schema::hasTable('communityevents_dates')) {
            return;
        }

        Schema::create('communityevents_dates', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->unsignedBigInteger('eventid')->index('eventid');
            $table->timestamp('start')->useCurrentOnUpdate()->useCurrent()->index('start');
            $table->timestamp('end')->default('0000-00-00 00:00:00')->index('end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communityevents_dates');
    }
};
