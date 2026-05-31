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
        if (Schema::hasTable('digest_retries')) {
            return;
        }

        Schema::create('digest_retries', function (Blueprint $table) {
            $table->comment('Per-recipient retry queue for digest sends that failed to build/render (e.g. a transient deploy-window template error), so the send is retried rather than silently dropped when the per-group cursor advances past it.');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid');
            $table->unsignedBigInteger('msgid');
            $table->unsignedBigInteger('groupid');
            $table->string('emailtype', 64)->default('digest_immediate');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('lasterror', 255)->nullable();
            $table->timestamp('nextattempt')->useCurrent();
            $table->timestamp('created')->useCurrent();

            // One queued retry per recipient+message+type; repeated failures
            // upsert (bump attempts) rather than creating duplicates.
            $table->unique(['userid', 'msgid', 'emailtype'], 'userid_msgid_emailtype');
            $table->index('nextattempt', 'nextattempt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digest_retries');
    }
};
