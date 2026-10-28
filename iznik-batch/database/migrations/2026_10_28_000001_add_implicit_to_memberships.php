<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * memberships.implicit: 1 while the member has not knowingly joined the community - their post
 * rippled there, or TrashNothing placed it there. It changes over time: it clears when they join,
 * move into the area or deal with the community.
 *
 * memberships.rippled does that job today alongside recording how the row was created; implicit
 * takes over the changing half, so that TrashNothing placements can carry it too. Additive:
 * rippled stays, and is written alongside implicit until every reader has moved.
 *
 * Added after an existing column with a default, so INSTANT on Percona 8.0. No index: every read
 * is already narrowed by user or group, as rippled's are.
 *
 * The copy is safe to run again, and should be run again just before the code that writes both
 * columns goes live: until then ripple joins and clears change rippled alone. It brings implicit
 * into line with rippled in both directions, in id ranges so no statement holds many rows.
 */
return new class extends Migration
{
    private const CHUNK = 10000;

    public function up(): void
    {
        if (! Schema::hasColumn('memberships', 'implicit')) {
            DB::statement('ALTER TABLE memberships '
                .'ADD COLUMN implicit TINYINT(1) NOT NULL DEFAULT 0 AFTER rippled, '
                .'ALGORITHM=INSTANT');
        }

        $maxId = (int) DB::table('memberships')->max('id');

        for ($from = 0; $from < $maxId; $from += self::CHUNK) {
            DB::update(
                'UPDATE memberships SET implicit = rippled WHERE id > ? AND id <= ? AND implicit <> rippled',
                [$from, $from + self::CHUNK]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('memberships', 'implicit')) {
            DB::statement('ALTER TABLE memberships DROP COLUMN implicit');
        }
    }
};
