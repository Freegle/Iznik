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
        if (Schema::hasTable('users_searches')) {
            return;
        }

        Schema::create('users_searches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->nullable()->index('userid_2');
            $table->timestamp('date')->useCurrentOnUpdate()->useCurrent();
            $table->string('term', 80);
            $table->unsignedBigInteger('maxmsg')->nullable()->index('maxmsg');
            $table->tinyInteger('deleted')->default(0);
            $table->unsignedBigInteger('locationid')->nullable()->index('locationid');

            $table->unique(['userid', 'term'], 'userid');
            $table->index(['userid', 'date'], 'userid_3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_searches');
    }
};
