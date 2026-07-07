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
        if (Schema::hasTable('users_comments')) {
            return;
        }

        Schema::create('users_comments', function (Blueprint $table) {
            $table->comment('Comments from mods on members');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->unsignedBigInteger('byuserid')->nullable()->index('modid');
            $table->timestamp('date')->useCurrent();
            $table->timestamp('reviewed')->useCurrentOnUpdate()->useCurrent()->index('reviewed');
            $table->mediumText('user1')->nullable();
            $table->mediumText('user2')->nullable();
            $table->mediumText('user3')->nullable();
            $table->mediumText('user4')->nullable();
            $table->mediumText('user5')->nullable();
            $table->mediumText('user6')->nullable();
            $table->mediumText('user7')->nullable();
            $table->mediumText('user8')->nullable();
            $table->mediumText('user9')->nullable();
            $table->mediumText('user10')->nullable();
            $table->mediumText('user11')->nullable();
            $table->boolean('flag')->default(false);

            $table->index(['userid', 'groupid'], 'userid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_comments');
    }
};
