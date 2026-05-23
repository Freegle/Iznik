<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class GroupMembershipDetectionTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() : void {
        parent::setUp();
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    /**
     * Bug #9518: groupsNear() uses haversine from group centres rather than ST_Contains polygon
     * containment. A point inside a large group polygon (Group A) but near a small group's
     * centre (Group B, whose polygon does NOT contain the point) returns Group B instead of A.
     *
     * AssertFlip protocol:
     *   STEP 1 — assertion matches BUGGY behaviour (assertTrue): test PASSES on current code.
     *   STEP 2 — assertion inverted (assertFalse): test FAILS on current code, PASSES after fix.
     *
     * Geometry (Tuvalu-area, no production groups):
     *   Test point: lng=179.265, lat=8.45
     *   Group A (large): poly lng 179.0..179.27, lat 8.35..8.55  — centre lng=179.177 (~6 mi away)
     *   Group B (small): poly lng 179.27..179.30, lat 8.43..8.47 — centre lng=179.285 (~1.4 mi away)
     *   ST_Contains(A.poly, point) = 1, ST_Contains(B.poly, point) = 0
     *   At the initial 4-mile search radius, only Group B's centre qualifies; loop exits with B only.
     */
    public function testGroupsNearReturnsContainingGroupNotNearestCentre() {
        $testLat = 8.45;
        $testLng = 179.265;

        // Group A: large polygon whose centre is ~6 miles from the test point
        $nameA = 'testGrpALarge_' . rand(100000, 999999);
        list($gA, $gidA) = $this->createTestGroup($nameA, Group::GROUP_REUSE);
        $gA->setPrivate('lat', 8.45);
        $gA->setPrivate('lng', 179.177);
        $gA->setPrivate('poly', 'POLYGON((179.0 8.35, 179.0 8.55, 179.27 8.55, 179.27 8.35, 179.0 8.35))');

        // Group B: small polygon whose centre is ~1.4 miles from the test point
        $nameB = 'testGrpBSmall_' . rand(100000, 999999);
        list($gB, $gidB) = $this->createTestGroup($nameB, Group::GROUP_REUSE);
        $gB->setPrivate('lat', 8.45);
        $gB->setPrivate('lng', 179.285);
        $gB->setPrivate('poly', 'POLYGON((179.27 8.43, 179.27 8.47, 179.30 8.47, 179.30 8.43, 179.27 8.43))');

        // Verify preconditions: point is inside A, outside B
        $srid = $this->dbhr->SRID();
        $inA = $this->dbhr->preQuery(
            "SELECT ST_Contains(polyindex, ST_GeomFromText('POINT($testLng $testLat)', $srid)) AS c FROM `groups` WHERE id = ?",
            [$gidA]
        );
        $inB = $this->dbhr->preQuery(
            "SELECT ST_Contains(polyindex, ST_GeomFromText('POINT($testLng $testLat)', $srid)) AS c FROM `groups` WHERE id = ?",
            [$gidB]
        );
        $this->assertEquals(1, $inA[0]['c'], 'Precondition: test point must be inside Group A polygon');
        $this->assertEquals(0, $inB[0]['c'], 'Precondition: test point must NOT be inside Group B polygon');

        // Create location at the test point
        $locName = 'TuvNearBoundary_' . rand(100000, 999999);
        list($l, $lid) = $this->createTestLocation(NULL, $locName, 'Road', "POINT($testLng $testLat)");

        // With limit=1, groupsNear iterates from ~4-mile radius upward.
        // At 4 miles: Group B centre (1.4 mi) qualifies; Group A centre (6 mi) does not.
        // Loop exits after first iteration returning [gidB]. Group A is never considered.
        $groups = $l->groupsNear(50, FALSE, 1);

        // STEP 2 (AssertFlip — must FAIL on buggy code):
        // Correct behaviour: Group B must NOT appear — the point is not inside its polygon.
        $this->assertFalse(
            in_array($gidB, $groups),
            'Bug #9518: Group B (close centre, non-containing polygon) must NOT be returned for a point inside Group A'
        );
    }
}
