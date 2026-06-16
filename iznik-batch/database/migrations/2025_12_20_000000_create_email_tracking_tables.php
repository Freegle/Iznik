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
        if (!Schema::hasTable('email_tracking')) {
            Schema::create('email_tracking', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('tracking_id', 32);
                $table->string('email_type', 50);
                $table->unsignedBigInteger('userid')->nullable();
                $table->unsignedBigInteger('groupid')->nullable();
                $table->string('recipient_email', 255);
                $table->string('subject', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('bounced_at')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('clicked_at')->nullable();
                $table->timestamp('unsubscribed_at')->nullable();
                $table->timestamps();

                // Index names mirror production exactly (the table predates this
                // migration there, with short column-named indexes). Identical
                // names mean query hints like FORCE INDEX(sent_at) resolve in
                // every environment, not just production.
                $table->unique('tracking_id', 'tracking_id');
                $table->index('email_type', 'email_type');
                $table->index('userid', 'userid');
                $table->index('groupid', 'groupid');
                $table->index('sent_at', 'sent_at');
                $table->index('opened_at', 'opened_at');
                $table->index('created_at', 'created_at');
            });
        }

        if (!Schema::hasTable('email_tracking_clicks')) {
            Schema::create('email_tracking_clicks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('email_tracking_id')->index();
                $table->string('link_url', 2048);
                $table->string('link_position', 50)->nullable();
                $table->string('action', 50)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->timestamp('clicked_at')->nullable()->index();

                $table->foreign('email_tracking_id')
                    ->references('id')
                    ->on('email_tracking')
                    ->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('email_tracking_images')) {
            Schema::create('email_tracking_images', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('email_tracking_id')->index();
                $table->string('image_position', 50);
                $table->unsignedTinyInteger('estimated_scroll_percent')->nullable();
                $table->timestamp('loaded_at')->nullable()->index();

                $table->foreign('email_tracking_id')
                    ->references('id')
                    ->on('email_tracking')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_tracking_images');
        Schema::dropIfExists('email_tracking_clicks');
        Schema::dropIfExists('email_tracking');
    }
};
