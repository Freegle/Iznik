<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The send mode for an active helper batch, orthogonal to status (pause/stop):
 *  - automatic: the FSM auto-sends simple conversational messages and proposes
 *    consequential decisions (the default);
 *  - approve: the FSM proposes EVERYTHING - no message (chat or email) is sent
 *    until the offerer approves it on the clearance page.
 *
 * Combined with status, the clearance page offers a three-way control:
 *  Paused (status=paused) / Approve (status=active, automode=approve) /
 *  Automatic (status=active, automode=automatic).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('helper_batches')) {
            return;
        }
        Schema::table('helper_batches', function (Blueprint $t) {
            if (! Schema::hasColumn('helper_batches', 'automode')) {
                $t->enum('automode', ['automatic', 'approve'])->default('automatic')->after('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('helper_batches')) {
            return;
        }
        Schema::table('helper_batches', function (Blueprint $t) {
            if (Schema::hasColumn('helper_batches', 'automode')) {
                $t->dropColumn('automode');
            }
        });
    }
};
