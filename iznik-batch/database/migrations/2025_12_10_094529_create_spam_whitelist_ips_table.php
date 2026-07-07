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
        if (Schema::hasTable('spam_whitelist_ips')) {
            return;
        }

        Schema::create('spam_whitelist_ips', function (Blueprint $table) {
            $table->comment('Whitelisted IP addresses');
            $table->bigInteger('id', true)->unique('id');
            $table->string('ip', 80)->unique('ip');
            $table->mediumText('comment');
            $table->timestamp('date')->useCurrent();

            $table->primary(['id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spam_whitelist_ips');
    }
};
