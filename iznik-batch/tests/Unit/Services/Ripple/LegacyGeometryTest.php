<?php

namespace Tests\Unit\Services\Ripple;

use App\Services\Ripple\LegacyGeometry;
use Tests\TestCase;

/**
 * The era guard. Everything about the drop rests on this answering correctly:
 * say "the columns exist" when they do not and every legacy branch names a
 * column that has gone; say the reverse and the transition era stops reading
 * rows the backfill has not reached yet.
 */
class LegacyGeometryTest extends TestCase
{
    protected function tearDown(): void
    {
        LegacyGeometry::reset();
        parent::tearDown();
    }

    public function test_reports_the_real_schema_by_default(): void
    {
        LegacyGeometry::reset();

        // The test schema keeps both forms (the drop migration is opt-in), so
        // the guard must say so. If this ever fails, the test database has
        // dropped the columns and every legacy-era test below it is lying.
        $this->assertTrue(LegacyGeometry::polygonReady(), 'the test schema should still carry polygon');
        $this->assertTrue(LegacyGeometry::overflowReady(), 'the test schema should still carry overflow_bounds');
    }

    public function test_the_answer_is_memoized(): void
    {
        LegacyGeometry::reset();
        $first = LegacyGeometry::polygonReady();

        // Same answer without re-asking. This is what lets every hot-path
        // caller use it freely - and also why a process must be restarted
        // after the drop.
        $this->assertSame($first, LegacyGeometry::polygonReady());
    }

    public function test_fake_overrides_each_era_independently(): void
    {
        LegacyGeometry::fake(polygon: false, overflow: true);
        $this->assertFalse(LegacyGeometry::polygonReady());
        $this->assertTrue(LegacyGeometry::overflowReady());

        // The two columns are dropped by separate statements, so a test must
        // be able to sit in the window between them.
        LegacyGeometry::fake(polygon: true, overflow: false);
        $this->assertTrue(LegacyGeometry::polygonReady());
        $this->assertFalse(LegacyGeometry::overflowReady());
    }

    public function test_reset_returns_to_the_real_schema(): void
    {
        LegacyGeometry::fake(polygon: false, overflow: false);
        $this->assertFalse(LegacyGeometry::polygonReady());

        LegacyGeometry::reset();

        // Not the stale override: a test that leaked its era would silently
        // change the meaning of every test after it.
        $this->assertTrue(LegacyGeometry::polygonReady());
        $this->assertTrue(LegacyGeometry::overflowReady());
    }
}
