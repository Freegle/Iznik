<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is sitting in the outbound relay's queue right now, per recipient domain.
 *
 * `mail_suppressions` answers "which provider has stopped accepting our mail".
 * That is one of the two reasons a member's email arrives late, and until this
 * table existed it was the only one anyone could see. The other is us: a warmed
 * sending address runs on a deliberate rate delay, and once its day allowance is
 * spent it slows further. Nothing is wrong when that happens - no error, no
 * deferral, no refusal - so a provider's whole backlog could sit hours deep with
 * the delayed view reporting that every provider was accepting our mail. Both
 * were true at once, and only one of them was the member's experience.
 *
 * So this records the queue itself: how much is waiting, how old the oldest is,
 * and how fast it is draining. Depth alone cannot be acted on - "3,000 queued"
 * is fine at 3,000/hour and a two-day outage at 60 - which is why the drain rate
 * is stored beside it rather than left to be guessed at.
 *
 * One row per recipient domain, rewritten by every scan. It is a snapshot, not a
 * history: rows for domains that have cleared are deleted rather than kept at
 * zero, so the table is the current state and nothing has to interpret a stale
 * row. `mail_suppressions` keeps the history of an episode; this does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_relay_queue')) {
            return;
        }

        Schema::create('mail_relay_queue', function (Blueprint $table) {
            $table->comment('Current outbound relay queue depth and drain rate, per recipient domain');

            // A surrogate key even though `domain` is unique and is the only
            // way this table is ever read: every table here has an id.
            $table->bigIncrements('id');

            $table->string('domain');

            // Queued with nothing refusing it - waiting on our own pacing.
            $table->unsignedInteger('waiting')->default(0)
                ->comment('Queued messages no provider has refused: waiting on our pacing');

            // Queued because a provider gave us a 4xx. Held here too so one
            // row tells the whole story of a domain rather than making the
            // reader join two tables to find out which half applies.
            $table->unsignedInteger('deferred')->default(0)
                ->comment('Queued messages a provider has refused with a 4xx');

            // Of the waiting ones. The age is the metric that matters: depth
            // says how much, age says how badly.
            $table->timestamp('oldest')->nullable()
                ->comment('Arrival time of the oldest waiting message');

            $table->unsignedInteger('deliveredperhour')->default(0)
                ->comment('Deliveries to this domain in the probe log window');

            // Which postfix instance holds it. A relay that paces providers
            // runs more than one, and an operator reaching for postqueue or
            // postsuper needs to know which, because queue ids are only
            // unique within an instance.
            $table->string('instance')->nullable();

            $table->timestamp('scanned')->useCurrent()
                ->comment('When the probe that wrote this row ran');

            $table->unique('domain');
            $table->index('waiting');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_relay_queue');
    }
};
