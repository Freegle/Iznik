<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A 'safeguarding' concern_keywords category, for posts that may show where somebody
 * escaping abuse lives (Discourse 9808/835).
 *
 * The enum is extended from whatever the column holds now, appending the new value, rather
 * than restating the list: production's physical order is the one that matters and a
 * restated list shifts stored rows if it differs.
 *
 * The keywords were added on production on 2026-10-02 as 'review'; this moves them by
 * keyword (never by id) and inserts any that are missing, so a fresh or test database ends
 * up the same. Safe to re-run.
 */
return new class extends Migration
{
    private const KEYWORDS = [
        'refuge', 'refuges', 'domestic violence', 'domestic abuse', 'dv', 'fleeing',
        'escaping abuse', 'escaped abuse', "women's aid", 'womens aid', 'safe house',
        'hostel', 'hostels', 'shelter', 'shelters',
    ];

    private const SHELTER_EXCLUDE = '(animal|dog|cat|bus|garden|bike|cycle|log|smoking|rain|pet|rescue|wood|bin|chicken|rabbit|beach|sun|tent|fishing)\s+shelters?';

    public function up(): void
    {
        if (!Schema::hasTable('concern_keywords')) {
            return;
        }

        $type = DB::selectOne("
            SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_keywords' AND COLUMN_NAME = 'category'
        ")->t;

        if (!str_contains($type, "'safeguarding'")) {
            $newType = preg_replace('/\)$/', ",'safeguarding')", $type);
            DB::statement("ALTER TABLE concern_keywords MODIFY COLUMN category {$newType} NOT NULL");
        }

        foreach (self::KEYWORDS as $keyword) {
            $exclude = in_array($keyword, ['shelter', 'shelters'], true) ? self::SHELTER_EXCLUDE : null;

            $existing = DB::table('concern_keywords')
                ->where('keyword', $keyword)
                ->where('scope', 'global')
                ->where('group_id', 0)
                ->first();

            if (!$existing) {
                DB::table('concern_keywords')->insert([
                    'keyword'    => $keyword,
                    'category'   => 'safeguarding',
                    'match_mode' => 'literal',
                    'exclude'    => $exclude,
                    'scope'      => 'global',
                    'group_id'   => 0,
                    'action'     => 'flag',
                ]);
                continue;
            }

            // Only a general 'review' row is ours to move; leave anything a human
            // categorised differently alone. An existing exclude is preserved.
            if ($existing->category === 'review') {
                DB::table('concern_keywords')->where('id', $existing->id)->update([
                    'category' => 'safeguarding',
                    'exclude'  => $existing->exclude ?? $exclude,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('concern_keywords')) {
            return;
        }

        DB::table('concern_keywords')->where('category', 'safeguarding')->update(['category' => 'review']);

        $type = DB::selectOne("
            SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_keywords' AND COLUMN_NAME = 'category'
        ")->t;

        if (str_contains($type, "'safeguarding'")) {
            $oldType = str_replace(",'safeguarding'", '', $type);
            DB::statement("ALTER TABLE concern_keywords MODIFY COLUMN category {$oldType} NOT NULL");
        }
    }
};
