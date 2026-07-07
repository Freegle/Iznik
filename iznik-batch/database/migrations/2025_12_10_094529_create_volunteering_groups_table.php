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
        if (Schema::hasTable('volunteering_groups')) {
            return;
        }

        Schema::create('volunteering_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('volunteeringid')->index('eventid');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->timestamp('arrival')->useCurrent();

            $table->unique(['volunteeringid', 'groupid'], 'eventid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('volunteering_groups');
    }
};
