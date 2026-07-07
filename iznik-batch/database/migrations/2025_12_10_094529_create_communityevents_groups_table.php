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
        if (Schema::hasTable('communityevents_groups')) {
            return;
        }

        Schema::create('communityevents_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('eventid')->index('eventid');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->timestamp('arrival')->useCurrent();

            $table->unique(['eventid', 'groupid'], 'eventid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communityevents_groups');
    }
};
