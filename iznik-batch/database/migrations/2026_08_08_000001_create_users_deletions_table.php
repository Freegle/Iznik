<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users_deletions - a tombstone per user we have destroyed.
 *
 * Partners (TrashNothing) mirror our users, and learn about our changes by polling
 * /api/changes for everything that has moved since a timestamp. Users are reported
 * from users.lastupdated, which only works while there is a row to read: once we
 * forget someone (their personal data is wiped) and later hard-delete them, they
 * simply stop appearing, and the partner keeps a copy of a member who asked to be
 * gone. This table is the record that outlives them, so the deletion is a change
 * the partner can see.
 *
 * Deliberately NO foreign key to users: the whole point is that the row survives
 * `DELETE FROM users`. userid is not unique either - forget-then-purge is two real
 * events, and reporting both is harmless because removal is idempotent.
 *
 * Rows are pruned once they are older than any sane partner polling window (see
 * UserManagementService::pruneDeletions).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users_deletions')) {
            return;
        }

        Schema::create('users_deletions', function (Blueprint $t) {
            $t->bigIncrements('id');

            // The Freegle user id - all a partner needs to remove their copy.
            $t->unsignedBigInteger('userid')->index('users_deletions_userid');

            // Polled by range, so this is the index that matters.
            $t->timestamp('timestamp')->useCurrent()->index('users_deletions_timestamp');

            // 'Forgotten' = personal data wiped, row still present.
            // 'Purged'    = the users row itself has now gone.
            // Ours for debugging; partners are told only that the user is gone.
            $t->enum('type', ['Forgotten', 'Purged'])->default('Forgotten');

            // Why, e.g. 'Grace period', 'Inactive', 'TN account removed'.
            $t->string('reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users_deletions');
    }
};
