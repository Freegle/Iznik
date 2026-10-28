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
