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
        if (Schema::hasTable('locations_excluded')) {
            return;
        }

        Schema::create('locations_excluded', function (Blueprint $table) {
            $table->comment('Stops locations being suggested on a group');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('locationid')->index('locationid');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->unsignedBigInteger('userid')->nullable()->index('by');
            $table->timestamp('date')->useCurrent();
            $table->boolean('norfolk')->default(false);

            $table->unique(['locationid', 'groupid'], 'locationid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations_excluded');
    }
};
