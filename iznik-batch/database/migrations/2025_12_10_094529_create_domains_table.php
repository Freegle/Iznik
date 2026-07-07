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
        if (Schema::hasTable('domains')) {
            return;
        }

        Schema::create('domains', function (Blueprint $table) {
            $table->comment('Statistics on email domains we\'ve sent to recently');
            $table->bigIncrements('id');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->string('domain')->unique('domain');
            $table->integer('sent');
            $table->integer('defers');
            $table->integer('avgdly');
            $table->boolean('problem')->default(false)->index('problem');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
