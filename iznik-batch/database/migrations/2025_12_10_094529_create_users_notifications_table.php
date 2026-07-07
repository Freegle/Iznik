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
        if (Schema::hasTable('users_notifications')) {
            return;
        }

        Schema::create('users_notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('fromuser')->nullable()->index('fromuser');
            $table->unsignedBigInteger('touser')->index('touser');
            $table->timestamp('timestamp')->useCurrent();
            $table->enum('type', ['CommentOnYourPost', 'CommentOnCommented', 'LovedPost', 'LovedComment', 'TryFeed', 'MembershipPending', 'MembershipApproved', 'MembershipRejected', 'AboutMe', 'Exhort', 'GiftAid', 'OpenPosts']);
            $table->unsignedBigInteger('newsfeedid')->nullable()->index('newsfeedid');
            $table->string('url')->nullable();
            $table->boolean('seen')->default(false);
            $table->tinyInteger('mailed')->default(0);
            $table->string('title', 80)->nullable();
            $table->text('text')->nullable();

            $table->index(['timestamp', 'seen', 'mailed'], 'touser_2');
            $table->index(['touser', 'id', 'seen'], 'userid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_notifications');
    }
};
