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
        if (Schema::hasTable('users_approxlocs')) {
            return;
        }

        Schema::create('users_approxlocs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->unique('userid');
            $table->decimal('lat', 10, 6);
            $table->decimal('lng', 10, 6);
            $table->geography('position', null, 3857);
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');

            $table->spatialIndex(['position'], 'position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_approxlocs');
    }
};
