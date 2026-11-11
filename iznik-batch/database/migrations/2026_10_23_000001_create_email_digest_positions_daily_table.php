<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_digest_positions_daily: for each day, digest email type and post position, how many digests
 * showed a post at that position and how many of them were clicked there. It is what the ModTools
 * SysAdmin "Digest click-through by position" chart sums, because working it out live from
 * email_tracking costs about five seconds per day and a 30 day range could not finish before the
 * gateway gave up.
 *
 * email_type '' with position 0 is a marker row, written for every day that has been rolled up
 * (all zero). A day with no digests at all has no other row, and the marker is how the API tells
 * "summarised, nothing sent" from "not summarised yet".
 *
 * A new, empty table. Nothing alters an existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_digest_positions_daily')) {
            Schema::create('email_digest_positions_daily', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('day');
                $t->string('email_type', 64);
                $t->unsignedSmallInteger('position');
                $t->unsignedInteger('shown')->default(0);
                $t->unsignedInteger('emails_clicked')->default(0);
                $t->unsignedInteger('clicks')->default(0);
                $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                $t->unique(['day', 'email_type', 'position'], 'email_digest_positions_daily_key');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_digest_positions_daily');
    }
};
