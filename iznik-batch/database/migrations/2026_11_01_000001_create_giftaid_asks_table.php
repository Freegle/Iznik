<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gift Aid has three answers, not two: consent given, consent declined, and asked with no reply.
 *
 * - giftaid_asks holds one row per time a donor was asked for a declaration and has not answered:
 *   the automated chase-up mail (askedby NULL) and a volunteer recording that they asked
 *   (askedby = the volunteer). A donor can be asked many times.
 * - giftaid.recordedby is the volunteer who recorded a donor's answer for them, so a decline the
 *   donor gave by email is not mistaken for one they made on the form, and no volunteer has to
 *   impersonate the donor to record it.
 *
 * The backfill turns each donor's first chase-up on users_donations.giftaidchaseup into an ask, so
 * the history before this table is kept. The first, because the chase-up job rewrites the column
 * to the current time when it tidies up, so the later values are not when we asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('giftaid_asks')) {
            Schema::create('giftaid_asks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('userid');
                $table->unsignedBigInteger('askedby')->nullable();
                $table->timestamp('timestamp')->useCurrent();
                $table->index(['userid', 'timestamp'], 'giftaid_asks_userid_timestamp');
                $table->foreign('userid')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('askedby')->references('id')->on('users')->onDelete('set null');
            });

            DB::statement("
                INSERT INTO giftaid_asks (userid, askedby, timestamp)
                SELECT users_donations.userid, NULL, MIN(users_donations.giftaidchaseup)
                FROM users_donations
                INNER JOIN users ON users.id = users_donations.userid
                WHERE users_donations.giftaidchaseup IS NOT NULL
                GROUP BY users_donations.userid
            ");
        }

        if (!Schema::hasColumn('giftaid', 'recordedby')) {
            Schema::table('giftaid', function (Blueprint $table) {
                $table->unsignedBigInteger('recordedby')->nullable()->after('housenameornumber');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('giftaid_asks');

        if (Schema::hasColumn('giftaid', 'recordedby')) {
            Schema::table('giftaid', function (Blueprint $table) {
                $table->dropColumn('recordedby');
            });
        }
    }
};
