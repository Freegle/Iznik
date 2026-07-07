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
        if (Schema::hasTable('vouchers')) {
            return;
        }

        Schema::create('vouchers', function (Blueprint $table) {
            $table->comment('For licensing groups');
            $table->bigIncrements('id');
            $table->string('voucher')->unique('voucher');
            $table->timestamp('created')->useCurrent();
            $table->timestamp('used')->nullable();
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid')->comment('Group that a voucher was used on');
            $table->unsignedBigInteger('userid')->nullable()->index('userid')->comment('User who redeemed a voucher');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
