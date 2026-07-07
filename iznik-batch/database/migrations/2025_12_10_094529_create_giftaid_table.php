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
        if (Schema::hasTable('giftaid')) {
            return;
        }

        Schema::create('giftaid', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->unique('userid');
            $table->timestamp('timestamp')->useCurrent();
            $table->enum('period', ['This', 'Since', 'Future', 'Declined', 'Past4YearsAndFuture'])->default('This');
            $table->string('fullname');
            $table->text('homeaddress');
            $table->timestamp('deleted')->nullable();
            $table->timestamp('reviewed')->nullable();
            $table->timestamp('updated')->useCurrentOnUpdate()->useCurrent();
            $table->string('postcode', 10)->nullable();
            $table->string('housenameornumber')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('giftaid');
    }
};
