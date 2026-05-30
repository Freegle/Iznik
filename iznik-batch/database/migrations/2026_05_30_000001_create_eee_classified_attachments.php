<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `eee_classified_attachments` — pointer set of (messageid, attid) pairs that
 * the EEE classifier has processed, so micro-volunteering can serve volunteers
 * the SAME attachments the model classified, not arbitrary recent OFFERs.
 *
 * Without this table, getEEELabelChallenge picked any recent OFFER with a
 * photo. That meant volunteers laboured on items the model never classified,
 * so Condition/Weight/Size labels could not be scored against any model
 * accuracy. (Confirmed on 2026-05-30: 161 MV-labelled msgids ∩
 * eee_classifications = 0.)
 *
 * The detailed classification data still lives in the iznik-batch SQLite
 * (eee_classifications) — this is only a pointer index for the MySQL-side
 * Go API to JOIN against.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eee_classified_attachments')) {
            return;
        }

        Schema::create('eee_classified_attachments', function ($table) {
            $table->comment('Pointer set of (messageid, attid) pairs the EEE classifier has processed. Joined by micro-volunteering to serve volunteers the same items the model saw.');
            $table->unsignedBigInteger('messageid');
            $table->unsignedBigInteger('attid');
            $table->timestamp('classified_at')->useCurrent();
            $table->primary(['messageid', 'attid']);
            $table->index('attid', 'idx_attid');
            $table->index('classified_at', 'idx_classified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eee_classified_attachments');
    }
};
