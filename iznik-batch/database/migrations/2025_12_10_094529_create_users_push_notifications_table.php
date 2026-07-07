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
        if (Schema::hasTable('users_push_notifications')) {
            return;
        }

        Schema::create('users_push_notifications', function (Blueprint $table) {
            $table->comment('For sending push notifications to users');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid');
            $table->timestamp('added')->useCurrent();
            $table->enum('type', ['Google', 'Firefox', 'Test', 'Android', 'IOS', 'FCMAndroid', 'FCMIOS'])->default('Google')->index('type');
            $table->timestamp('lastsent')->nullable()->useCurrent();
            $table->string('subscription')->unique('subscription');
            $table->enum('apptype', ['User', 'ModTools'])->default('User');
            $table->timestamp('engageconsidered')->nullable();
            $table->timestamp('engagesent')->nullable();

            $table->index(['userid', 'type'], 'userid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_push_notifications');
    }
};
