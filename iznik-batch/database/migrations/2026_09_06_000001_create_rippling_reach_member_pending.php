<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rippling_reach_member_pending - members whose eligibility for reach mail changed.
 *
 * Reach mail used to find late-eligible members by re-examining every post whose
 * reach had changed in the last 60 minutes, once a minute, hoping the member had
 * changed inside that window. That window was 47-68% of db2 and still missed
 * anyone who joined, moved, returned or switched to immediate mail more than an
 * hour after a post's reach settled. The codepaths that change a member now write
 * a row here instead, and the reach mail job drains it.
 *
 * One row per member (UNIQUE userid): repeated signals before the drain collapse
 * to one, keeping the latest reason. Expected volume is one or two rows a minute,
 * drained every minute, so the table stays near empty.
 *
 * Its own table rather than a column on users (2.85M rows; every extra index costs
 * all three Galera nodes on every write) or a row in logs (42.6M rows; purge:logs
 * would delete unprocessed work).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rippling_reach_member_pending', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid');
            $table->enum('reason', ['joined', 'moved', 'returned', 'frequency']);
            $table->timestamp('added')->useCurrent();
            $table->unique('userid', 'userid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rippling_reach_member_pending');
    }
};
