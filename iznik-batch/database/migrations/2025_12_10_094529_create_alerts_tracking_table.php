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
        if (Schema::hasTable('alerts_tracking')) {
            return;
        }

        Schema::create('alerts_tracking', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('alertid')->index('alertid');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->unsignedBigInteger('emailid')->nullable()->index('emailid');
            $table->enum('type', ['ModEmail', 'OwnerEmail', 'PushNotif', 'ModToolsNotif']);
            $table->timestamp('sent')->useCurrent();
            $table->timestamp('responded')->nullable();
            $table->enum('response', ['Read', 'Clicked', 'Bounce', 'Unsubscribe'])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts_tracking');
    }
};
