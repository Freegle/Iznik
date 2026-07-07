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
        if (Schema::hasTable('groups_facebook_shares')) {
            return;
        }

        Schema::create('groups_facebook_shares', function (Blueprint $table) {
            $table->unsignedBigInteger('uid')->index('uid');
            $table->unsignedBigInteger('groupid')->index('groupid_2');
            $table->string('postid', 128)->index('postid');
            $table->timestamp('date')->useCurrent()->index('date');
            $table->enum('status', ['Shared', 'Hidden', '', ''])->default('Shared');

            $table->unique(['uid', 'postid'], 'groupid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups_facebook_shares');
    }
};
