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
        if (Schema::hasTable('messages_spatial')) {
            return;
        }

        Schema::create('messages_spatial', function (Blueprint $table) {
            $table->comment('Recent open messages with locations');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->unique('msgid');
            $table->geography('point', null, 3857);
            $table->boolean('successful')->default(false);
            $table->boolean('promised')->default(false);
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->enum('msgtype', ['Offer', 'Taken', 'Wanted', 'Received', 'Admin', 'Other'])->nullable();
            $table->timestamp('arrival')->nullable();

            $table->spatialIndex(['point'], 'point');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_spatial');
    }
};
