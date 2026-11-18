<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach_member_since - when each member last became eligible for reach mail.
 *
 * A member becomes eligible by changing postcode, returning after a long absence or switching
 * to immediate mail. (Joining is per community and is read from memberships.added.) Reach mail
 * does not tell them about a post that arrived on their community more than the look-back
 * before that moment, however often that post's reach changes afterwards. The reach pass writes the row as it drains
 * rippling_reach_member_pending and reads it in the recipient query.
 *
 * One row per member (UNIQUE userid), holding the latest time. A member never queued has no
 * row and is not limited. Rows are kept: a post can stay open for months, and the table is
 * bounded by the number of members.
 *
 * Production SQL: 2026_11_18_000001_create_rippling_reach_member_since_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rippling_reach_member_since')) {
            return;
        }

        Schema::create('rippling_reach_member_since', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid');
            $table->timestamp('since')->useCurrent();
            $table->unique('userid', 'rippling_reach_member_since_userid');
            $table->foreign('userid', 'rippling_reach_member_since_userid_foreign')
                ->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_reach_member_since');
    }
};
