<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_held_replies.dueat - when a held reply is due to be delivered.
 *
 * A hold is a DELAY, and this is when it ends: computed from how far the replier is
 * from the item (see RippleReplyService::delayMinutesForMiles). Locals still go
 * first; nobody is invisible for days. Waiting for the reach to arrive instead is
 * no delay at all for most repliers, because three in four of them live somewhere
 * it never covers, so they would sit on the max-reach backstop days later - by
 * which time a quarter to a third of items have gone.
 *
 * Nullable, because the Go/web hold path does not compute it - the release sweep
 * fills it in on its first pass, so the policy lives in exactly one place. NULL
 * therefore means "not yet stamped", not "never".
 *
 * Read `dueat` alongside `releasedat` carefully: dueat is when the row is due to
 * come off hold (in the future while it is still held), releasedat is when it
 * actually came off (NULL until then).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rippling_held_replies', 'dueat')) {
            return;
        }

        Schema::table('rippling_held_replies', function (Blueprint $t) {
            $t->timestamp('dueat')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('rippling_held_replies', 'dueat')) {
            return;
        }

        Schema::table('rippling_held_replies', function (Blueprint $t) {
            $t->dropColumn('dueat');
        });
    }
};
