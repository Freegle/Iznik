<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ux_flow_signal_daily: for each step (and, for field trouble, each field), how many separate visits
 * showed a sign of second thoughts, and how many of those visits went on to finish the flow. It is
 * what lets the SysAdmin UX tab say whether going back, a changed field or a call for help is
 * followed by giving up or by finishing anyway. The other ux_* tables count events and cannot say
 * that, because they never join an event to the end of its own visit.
 *
 * signal is one of: reached (the visit entered the step; the base to compare with), backed,
 * help, field (any revisit, validation failure or choice switch on that field). people counts
 * distinct flow instances, so a visit that went back three times is one.
 *
 * A new, empty table. Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ux_flow_signal_daily')) {
            Schema::create('ux_flow_signal_daily', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('date');
                $t->string('flow', 32);
                $t->string('step', 64);
                $t->string('field', 64)->default('');
                $t->string('signal', 16);
                $t->string('device_class', 16)->default('');
                $t->boolean('logged_in')->default(false);
                $t->boolean('is_app')->default(false);
                $t->string('variant', 64)->default('');
                $t->unsignedInteger('people')->default(0);
                $t->unsignedInteger('finished')->default(0);
                $t->unique(
                    ['date', 'flow', 'step', 'field', 'signal', 'device_class', 'logged_in', 'is_app', 'variant'],
                    'ux_flow_signal_daily_key'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ux_flow_signal_daily');
    }
};
