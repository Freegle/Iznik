<?php

namespace Tests\Unit\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MembershipImplicitColumnTest extends TestCase
{
    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_28_000001_add_implicit_to_memberships.php');
    }

    public function test_memberships_implicit_is_a_not_null_flag_defaulting_to_zero(): void
    {
        $col = DB::selectOne("SELECT COLUMN_TYPE AS t, IS_NULLABLE AS n, COLUMN_DEFAULT AS d
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'memberships' AND COLUMN_NAME = 'implicit'");

        $this->assertNotNull($col);
        $this->assertSame('tinyint(1)', $col->t);
        $this->assertSame('NO', $col->n);
        $this->assertSame('0', (string) $col->d);
    }

    public function test_a_membership_inserted_without_implicit_gets_the_default(): void
    {
        $membership = $this->createMembership($this->createTestUser(), $this->createTestGroup());

        $this->assertFalse($membership->refresh()->implicit);
    }

    /**
     * Ripple joins and clears write only rippled until the code writes both columns, so the copy
     * has to be safe to run again just before that ships: it brings implicit into line with
     * rippled in both directions, and leaves everything else alone.
     */
    public function test_up_copies_rippled_into_implicit_in_both_directions_and_can_run_again(): void
    {
        $user = $this->createTestUser();
        $rippled = $this->createMembership($user, $this->createTestGroup(), ['rippled' => 1]);
        $clearedSinceTheCopy = $this->createMembership($user, $this->createTestGroup(), ['rippled' => 0]);
        $ordinary = $this->createMembership($user, $this->createTestGroup(), ['rippled' => 0]);
        DB::table('memberships')->where('id', $clearedSinceTheCopy->id)->update(['implicit' => 1]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertTrue($rippled->refresh()->implicit);
        $this->assertFalse($clearedSinceTheCopy->refresh()->implicit);
        $this->assertFalse($ordinary->refresh()->implicit);
    }
}
