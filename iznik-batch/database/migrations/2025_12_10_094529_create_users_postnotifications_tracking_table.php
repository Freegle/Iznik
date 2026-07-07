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
        if (Schema::hasTable('users_postnotifications_tracking')) {
            return;
        }

        Schema::create('users_postnotifications_tracking', function (Blueprint $table) {
            $table->comment('Tracks which posts we have sent push notifications about');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->integer('frequency')->comment('Digest frequency constant (-1=immediate, 1=hourly, 24=daily, etc.)');
            $table->timestamp('msgdate')->nullable()->comment('Arrival of message we have sent notifications up to');
            $table->timestamp('lastsent')->nullable()->comment('When we last sent notifications for this group/frequency');

            $table->unique(['groupid', 'frequency'], 'groupid_frequency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_postnotifications_tracking');
    }
};
