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
        if (Schema::hasTable('spam_whitelist_links')) {
            return;
        }

        Schema::create('spam_whitelist_links', function (Blueprint $table) {
            $table->comment('Whitelisted domains for URLs');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->string('domain', 80)->unique('domain');
            $table->timestamp('date')->useCurrentOnUpdate()->useCurrent();
            $table->integer('count')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spam_whitelist_links');
    }
};
