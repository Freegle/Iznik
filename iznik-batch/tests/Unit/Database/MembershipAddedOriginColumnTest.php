<?php

namespace Tests\Unit\Database;

use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MembershipAddedOriginColumnTest extends TestCase
{
    /**
     * The ENUM and the model's list are written separately, so a value added to one and not the
     * other would only show up as a refused insert. Both tables carry the same list.
     */
    public function test_added_origin_holds_exactly_the_model_values_on_both_tables(): void
    {
        $expected = "enum('" . implode("','", Membership::ADDED_ORIGINS) . "')";

        foreach (['memberships', 'memberships_history'] as $table) {
            $col = DB::selectOne("SELECT COLUMN_TYPE AS t, IS_NULLABLE AS n, COLUMN_DEFAULT AS d
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'added_origin'", [$table]);

            $this->assertNotNull($col, $table);
            $this->assertSame($expected, $col->t, $table);
            $this->assertSame('NO', $col->n, $table);
            $this->assertSame(Membership::ADDED_ORIGIN_UNKNOWN, $col->d, $table);
        }
    }

    /**
     * Production can only run the hand-written .sql, which spells the ENUM out twice, once per
     * table. Each must list exactly the model's values, in order, or production's column would
     * differ from the one the tests run against.
     */
    public function test_the_production_sql_lists_exactly_the_model_values(): void
    {
        $sql = file_get_contents(database_path('migrations/2026_10_28_000002_add_added_origin_to_memberships_migration.sql'));
        $expected = "ENUM(''" . implode("'',''", Membership::ADDED_ORIGINS) . "'')";

        preg_match_all("/ADD COLUMN added_origin (ENUM\\([^)]*\\))/", $sql, $matches);

        $this->assertCount(2, $matches[1], 'one ENUM per table');
        foreach ($matches[1] as $enum) {
            $this->assertSame($expected, $enum);
        }
    }

    public function test_a_membership_inserted_without_an_origin_gets_unknown(): void
    {
        $membership = $this->createMembership($this->createTestUser(), $this->createTestGroup());

        $this->assertSame(Membership::ADDED_ORIGIN_UNKNOWN, $membership->refresh()->added_origin);
    }

    public function test_a_membership_keeps_the_origin_it_was_inserted_with(): void
    {
        $membership = $this->createMembership($this->createTestUser(), $this->createTestGroup(), [
            'added_origin' => Membership::ADDED_ORIGIN_MANUAL,
        ]);

        $this->assertSame(Membership::ADDED_ORIGIN_MANUAL, $membership->refresh()->added_origin);
    }
}
