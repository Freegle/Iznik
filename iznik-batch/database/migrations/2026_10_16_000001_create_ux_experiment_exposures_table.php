<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ux_experiment_exposures: one row each time server code uses a member's experiment arm to decide
 * what they get (an email, a push, a chat message, a promise). The arm follows from the member's id
 * and the published weights, so the row adds no personal data of its own. It is the cohort an
 * experiment decided on the server is counted from, because the flow events in Loki cannot be split
 * by a server arm. docs/developers/reference/ux-experiments.md, "Experiments in mail, push and the
 * server".
 *
 * subject_type and subject_id name the thing decided: email / email_tracking.id, push / days since
 * 1970-01-01 UTC, chat or reply / chat_messages.id, bell / users_notifications.id, promise /
 * messages_promises.id, view / messages.id. detail holds ids only. userid is in the unique key so a
 * push, whose subject is the day, is one row per member per day.
 *
 * A new, empty table. Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ux_experiment_exposures')) {
            Schema::create('ux_experiment_exposures', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('experiment', 64);
                $t->string('arm', 16);
                $t->unsignedBigInteger('userid');
                $t->string('subject_type', 16);
                $t->unsignedBigInteger('subject_id');
                $t->string('stratum', 16)->nullable();
                $t->timestamp('exposed_at')->useCurrent();
                $t->json('detail')->nullable();
                $t->unique(['experiment', 'subject_type', 'subject_id', 'userid'], 'ux_exposures_subject');
                $t->index(['experiment', 'userid', 'id'], 'ux_exposures_user');
                $t->index(['experiment', 'exposed_at'], 'ux_exposures_time');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ux_experiment_exposures');
    }
};
