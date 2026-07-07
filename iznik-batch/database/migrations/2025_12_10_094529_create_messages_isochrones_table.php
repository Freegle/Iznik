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
        if (Schema::hasTable('messages_isochrones')) {
            return;
        }

        Schema::create('messages_isochrones', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->index('msgid');
            $table->unsignedBigInteger('isochroneid')->index('isochroneid');
            $table->integer('minutes');
            $table->integer('activeUsers')->default(0)->comment('Number of active users in isochrone when created');
            $table->integer('replies')->default(0)->comment('Number of replies when isochrone was created');
            $table->integer('views')->default(0)->comment('Number of views when isochrone was created');
            $table->timestamp('timestamp')->useCurrent()->index('timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_isochrones');
    }
};
