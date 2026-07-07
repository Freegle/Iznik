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
        if (Schema::hasTable('users_chatlists_index')) {
            return;
        }

        Schema::create('users_chatlists_index', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chatid')->index('chatid_2');
            $table->unsignedBigInteger('chatlistid')->index('chatid');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');

            $table->unique(['chatid', 'chatlistid', 'userid'], 'chatid_3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_chatlists_index');
    }
};
