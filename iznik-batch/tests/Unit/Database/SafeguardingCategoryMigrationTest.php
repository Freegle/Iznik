<?php

namespace Tests\Unit\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SafeguardingCategoryMigrationTest extends TestCase
{
    private const KEYWORDS = [
        'refuge', 'refuges', 'domestic violence', 'domestic abuse', 'dv', 'fleeing',
        'escaping abuse', 'escaped abuse', "women's aid", 'womens aid', 'safe house',
        'hostel', 'hostels', 'shelter', 'shelters',
    ];

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_02_000002_add_safeguarding_concern_category.php');
    }

    private function row(string $keyword): ?object
    {
        return DB::table('concern_keywords')
            ->where('keyword', $keyword)->where('scope', 'global')->where('group_id', 0)->first();
    }

    public function test_every_safeguarding_keyword_is_global_literal_flag(): void
    {
        foreach (self::KEYWORDS as $keyword) {
            $row = $this->row($keyword);
            $this->assertNotNull($row, "missing keyword: {$keyword}");
            $this->assertSame('safeguarding', $row->category, $keyword);
            $this->assertSame('literal', $row->match_mode, $keyword);
            $this->assertSame('flag', $row->action, $keyword);
        }
    }

    public function test_shelter_rows_keep_their_exclusion(): void
    {
        foreach (['shelter', 'shelters'] as $keyword) {
            $this->assertStringContainsString('animal|dog|cat', (string) $this->row($keyword)->exclude);
        }
        $this->assertNull($this->row('refuge')->exclude);
    }

    /**
     * Production already holds these as 'review' (ids 3838-3880, added by hand on 2026-10-02):
     * the migration moves them by keyword, keeps a hand-set exclude, inserts the missing, and
     * can be run again.
     */
    public function test_moves_existing_review_rows_inserts_missing_and_is_idempotent(): void
    {
        DB::table('concern_keywords')->where('keyword', 'refuge')->update(['category' => 'review']);
        DB::table('concern_keywords')->where('keyword', 'shelter')->update(['category' => 'review', 'exclude' => 'custom\s+shelter']);
        DB::table('concern_keywords')->where('keyword', 'dv')->delete();
        // A row a human categorised differently is not ours to move.
        DB::table('concern_keywords')->where('keyword', 'hostel')->update(['category' => 'allowed']);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('safeguarding', $this->row('refuge')->category);
        $this->assertSame('custom\s+shelter', $this->row('shelter')->exclude, 'an existing exclude is preserved');
        $this->assertSame('safeguarding', $this->row('shelter')->category);
        $this->assertSame('safeguarding', $this->row('dv')->category, 'a missing keyword is inserted');
        $this->assertSame('allowed', $this->row('hostel')->category);
        $this->assertSame(
            1,
            DB::table('concern_keywords')->where('keyword', 'dv')->where('scope', 'global')->count(),
            'no duplicates after a second run'
        );

        // Restore the hand-edited rows for later tests.
        DB::table('concern_keywords')->where('keyword', 'hostel')->update(['category' => 'safeguarding']);
        DB::table('concern_keywords')->where('keyword', 'shelter')->update([
            'exclude' => '(animal|dog|cat|bus|garden|bike|cycle|log|smoking|rain|pet|rescue|wood|bin|chicken|rabbit|beach|sun|tent|fishing)\s+shelters?',
        ]);
    }

    public function test_enum_append_keeps_the_existing_order(): void
    {
        $type = DB::selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concern_keywords' AND COLUMN_NAME = 'category'")->t;

        $this->assertStringStartsWith(
            "enum('substance_regulated','substance_reportable','substance_medicine','scam','review','allowed'",
            $type
        );
        $this->assertStringEndsWith(",'safeguarding')", $type);
    }
}
