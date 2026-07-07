<?php

namespace Tests\Unit\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConcernKeywordsSchemaTest extends TestCase
{
    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('concern_keywords'));
    }

    public function test_has_required_columns(): void
    {
        foreach (['id', 'keyword', 'category', 'match_mode', 'action', 'scope'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('concern_keywords', $col),
                "Missing column: {$col}"
            );
        }
    }

    public function test_global_entry_uses_group_id_zero(): void
    {
        $this->assertTrue(Schema::hasColumn('concern_keywords', 'group_id'));

        $keyword = 'test-schema-global-' . uniqid();
        DB::table('concern_keywords')->insertOrIgnore([
            'keyword'    => $keyword,
            'category'   => 'review',
            'match_mode' => 'literal',
            'action'     => 'flag',
            'scope'      => 'global',
            'group_id'   => 0,
        ]);

        $this->assertDatabaseHas('concern_keywords', ['keyword' => $keyword, 'scope' => 'global', 'group_id' => 0]);
        DB::table('concern_keywords')->where('keyword', $keyword)->delete();
    }

    public function test_category_enum_values(): void
    {
        $rows = DB::select("
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'concern_keywords'
              AND COLUMN_NAME = 'category'
        ");

        $this->assertNotEmpty($rows);
        $colType = $rows[0]->COLUMN_TYPE;

        foreach (['substance_regulated', 'substance_reportable', 'substance_medicine', 'scam', 'review', 'allowed'] as $val) {
            $this->assertStringContainsString($val, $colType, "category enum missing value: {$val}");
        }
    }

    public function test_scope_enum_values(): void
    {
        $rows = DB::select("
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'concern_keywords'
              AND COLUMN_NAME = 'scope'
        ");

        $this->assertNotEmpty($rows);
        $this->assertStringContainsString('global', $rows[0]->COLUMN_TYPE);
        $this->assertStringContainsString('group', $rows[0]->COLUMN_TYPE);
    }
}
