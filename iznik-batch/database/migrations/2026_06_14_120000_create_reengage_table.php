<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `reengage` table tracks the localised re-engagement *sequence* (one
     * row per email sent), distinct from the older `engage` table which the
     * late-stage AtRisk/Inactive win-back uses. A user's current position in
     * the sequence is derived from the rows whose `sentat` is newer than the
     * user's `lastaccess`: if they log in (or click an auto-login CTA) their
     * `lastaccess` jumps past every prior send, so the sequence resets itself
     * with no extra bookkeeping.
     */
    public function up(): void
    {
        if (Schema::hasTable('reengage')) {
            return;
        }

        Schema::create('reengage', function (Blueprint $table) {
            $table->comment('First-week onboarding tip sends (one row per tip, day 1-5)');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            // The tip/day number (1..5) in the onboarding sequence.
            $table->unsignedTinyInteger('stage');
            $table->string('template', 32)->nullable();
            $table->timestamp('sentat')->useCurrent()->index('sentat');
            // Terminal/analytics state: 'Reengaged' once a tip drove a real action
            // (login/reply/post) in the outcome window; 'Suppressed' once the
            // sequence completes with no such action.
            $table->enum('outcome', ['Reengaged', 'Suppressed'])->nullable();

            $table->index(['userid', 'sentat'], 'userid_sentat');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reengage');
    }
};
