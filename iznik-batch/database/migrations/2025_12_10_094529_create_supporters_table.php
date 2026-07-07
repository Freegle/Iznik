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
        if (Schema::hasTable('supporters')) {
            return;
        }

        Schema::create('supporters', function (Blueprint $table) {
            $table->comment('People who have supported this site');
            $table->bigIncrements('id')->unique('id');
            $table->string('name')->nullable();
            $table->enum('type', ['Wowzer', 'Front Page', 'Supporter', 'Buyer']);
            $table->string('email')->unique('email');
            $table->string('display')->nullable()->index('display');
            $table->string('voucher')->comment('Voucher code');
            $table->integer('vouchercount')->default(1)->comment('Number of licenses in this voucher');
            $table->integer('voucheryears')->default(1)->comment('Number of years voucher licenses are valid for');
            $table->boolean('anonymous')->default(false);

            $table->index(['name', 'type', 'email'], 'name');
            $table->primary(['id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supporters');
    }
};
