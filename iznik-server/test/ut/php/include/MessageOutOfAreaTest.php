<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');

/**
 * Demonstrates the "possibly out of area" false positive bug:
 *
 * When a message has locationid pointing inside the group polygon (from subject postcode)
 * but message.lat/lng pointing outside (from user IP/profile/TN coordinates),
 * ModTools incorrectly flags the message as "possibly out of area" because
 * location.groupsnear is null — forcing the frontend to fall back to message.lat/lng.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class MessageOutOfAreaTest extends IznikTestCase {
    private $dbhr, $dbhm;
    private $group, $gid;

    protected function setUp() : void {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM messages WHERE fromaddr = 'outofarea@test.com';");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");

        // Tuvalu grid setup (same pattern as MessageTest).
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 8.3 AND swlat <= 8.7;");
        $this->deleteLocations("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");

        for ($swlat = 8.3; $swlat <= 8.6; $swlat += 0.1) {
            for ($swlng = 179.1; $swlng <= 179.3; $swlng += 0.1) {
                $nelat = $swlat + 0.1;
                $nelng = $swlng + 0.1;
                $dbhm->preExec(
                    "INSERT IGNORE INTO locations_grids (swlat, swlng, nelat, nelng, box) VALUES (?, ?, ?, ?, ST_GeomFromText('POLYGON(($swlng $swlat, $nelng $swlat, $nelng $nelat, $swlng $nelat, $swlng $swlat))', {$this->dbhr->SRID()}))",
                    [$swlat, $swlng, $nelat, $nelng]
                );
            }
        }

        list($this->group, $this->gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $this->group->setPrivate('onhere', 1);
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.2);
        // Give the group a polygon covering the Tuvalu test area so groupsNear() can find it.
        $this->dbhm->preExec(
            "UPDATE `groups` SET polyindex = ST_GeomFromText('POLYGON((179.1 8.3, 179.3 8.3, 179.3 8.7, 179.1 8.7, 179.1 8.3))', {$this->dbhr->SRID()}) WHERE id = ?",
            [$this->gid]
        );
    }

    /**
     * FAILING TEST — demonstrates the "possibly out of area" false positive.
     *
     * Setup:
     *   - Group polygon covers Tuvalu (lat 8.3–8.7, lng 179.1–179.3)
     *   - Subject postcode "Tuvalu High Street" resolves inside that polygon
     *   - message.lat/lng deliberately set to London (outside polygon), simulating
     *     divergence from user IP/profile or a TN post-coordinates header
     *
     * Expected (after fix):
     *   - getPublic() returns location.groupsnear populated with the Tuvalu group
     *   - ModTools findHomeGroup() uses location.groupsnear instead of falling back
     *     to message.lat/lng, so the message is NOT flagged as "possibly out of area"
     *
     * Actual today:
     *   - Location::getPublic() never calls groupsNear(); 'groupsnear' key is absent
     *   - ModTools findHomeGroup() falls back to message.lat/lng (London), finds a
     *     different homegroup, and incorrectly flags the message as out of area
     */
    public function testLocationGroupsnearPopulatedForModeratorView() {
        // Subject postcode resolves to a location inside the group polygon.
        $l = new Location($this->dbhr, $this->dbhm);
        $locId = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');
        $this->assertNotNull($locId, 'Should create Tuvalu location');

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'outofarea@test.com', 'to@test.com', $msg);
        list($id, $failok) = $m->save();
        $this->assertGreaterThan(0, $id, 'Message should be saved');

        $m = new Message($this->dbhr, $this->dbhm, $id);

        // Simulate divergence: locationid is inside the group polygon (subject postcode),
        // but lat/lng reflects the user's IP/profile location outside the polygon (London).
        // This matches the production scenario for TN posts or manually-corrected locations.
        $m->setPrivate('locationid', $locId);
        $m->setPrivate('lat', 51.5);   // London lat — outside Tuvalu polygon
        $m->setPrivate('lng', -0.1);   // London lng — outside Tuvalu polygon

        // Confirm the divergence exists between message.lat and location.lat.
        $locAtts = $l->getPublic();
        $this->assertNotEquals(
            $m->getPrivate('lat'),
            $locAtts['lat'],
            'Test setup: message.lat/lng must differ from locationid coordinates'
        );

        // Get the moderator view (seeall=TRUE so the location field is returned).
        $atts = $m->getPublic(FALSE, FALSE, TRUE);

        $this->assertArrayHasKey('location', $atts,
            'Moderator view must include the location field');

        // FAILS TODAY: Location::getPublic() does not include 'groupsnear'.
        // Without groupsnear the frontend (ModTools findHomeGroup()) falls back to
        // message.lat/lng which points to London, finds no match with the Tuvalu group,
        // and incorrectly shows "possibly should be on <London group>".
        $this->assertArrayHasKey('groupsnear', $atts['location'],
            'location must contain groupsnear so ModTools does not fall back to message.lat/lng');

        $this->assertNotEmpty($atts['location']['groupsnear'],
            'location.groupsnear must identify at least one group near the subject postcode coordinates');

        $groupIds = array_column($atts['location']['groupsnear'], 'id');
        $this->assertContains(
            $this->gid,
            $groupIds,
            'The group whose polygon contains the subject postcode must appear in location.groupsnear'
        );
    }
}
