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
        if (Schema::hasTable('users_donations')) {
            return;
        }

        Schema::create('users_donations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('type', ['PayPal', 'External', 'Other', 'Stripe'])->default('PayPal');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->string('Payer', 80)->index('payer');
            $table->string('PayerDisplayName', 80);
            $table->timestamp('timestamp')->useCurrent();
            $table->string('TransactionID', 80)->nullable()->unique('transactionid');
            $table->decimal('GrossAmount', 10)->index('grossamount');
            $table->enum('source', ['DonateWithPayPal', 'PayPalGivingFund', 'Facebook', 'eBay', 'BankTransfer', 'Stripe'])->default('DonateWithPayPal')->index('source');
            $table->boolean('giftaidconsent')->default(false);
            $table->timestamp('giftaidclaimed')->nullable();
            $table->timestamp('giftaidchaseup')->nullable();
            $table->string('TransactionType', 32)->nullable();

            $table->index(['timestamp', 'GrossAmount'], 'timestamp');
            $table->index(['timestamp', 'userid', 'GrossAmount'], 'timestamp_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_donations');
    }
};
