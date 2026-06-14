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
            $table->comment('Localised re-engagement sequence sends (stage 1-3)');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            // 1 = nearby activity, 2 = local impact, 3 = preferences/opt-in.
            $table->unsignedTinyInteger('stage');
            $table->string('template', 32);
            $table->timestamp('sentat')->useCurrent()->index('sentat');
            // Terminal state for analytics: Suppressed once the 3-mail sequence
            // ends with no re-engagement. (Re-engagement is detected implicitly
            // via lastaccess, so it does not need to be written here.)
            $table->enum('outcome', ['Reengaged', 'Suppressed'])->nullable();

            $table->index(['userid', 'sentat'], 'userid_sentat');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reengage');
    }
};
