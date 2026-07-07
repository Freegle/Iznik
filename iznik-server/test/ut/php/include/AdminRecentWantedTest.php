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
class AdminRecentWantedTest extends IznikTestCase
{
    private $dbhr, $dbhm;
    private $gid;
    private $uid;

    protected function setUp(): void
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->preExec("DELETE FROM admins WHERE subject LIKE 'UT %';");
        $this->dbhm->preExec("DELETE FROM messages WHERE subject LIKE 'WANTED: UT %';");

        list($g, $this->gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        list($user, $this->uid) = $this->createTestUserWithLogin('Test User', 'testpw');
        $user->addMembership($this->gid);
    }

    protected function tearDown(): void
    {
        $this->dbhm->preExec("DELETE FROM admins WHERE subject LIKE 'UT %';");
        $this->dbhm->preExec("DELETE FROM messages WHERE subject LIKE 'WANTED: UT %';");
        parent::tearDown();
    }

    private function insertWantedMessage(string $subject, string $collection, string $arrival, ?int $heldby = null): int
    {
        $this->dbhm->preExec(
            "INSERT INTO messages (subject, fromuser, fromaddr, arrival, date, type, message, source)
             VALUES (?, ?, 'test@test.com', ?, ?, 'Wanted', 'test body', 'Email')",
            [$subject, $this->uid, $arrival, $arrival]
        );
        $msgid = $this->dbhm->lastInsertId();

        $this->dbhm->preExec(
            "INSERT INTO messages_groups (msgid, groupid, collection, arrival) VALUES (?, ?, ?, ?)",
            [$msgid, $this->gid, $collection, $arrival]
        );

        if ($heldby !== null) {
            $this->dbhm->preExec(
                "UPDATE messages SET heldby = ? WHERE id = ?",
                [$heldby, $msgid]
            );
        }

        return $msgid;
    }

    /**
     * AssertFlip STEP 2 (inverted): asserts the CORRECT behaviour that must hold after the fix.
     *
     * Bug: $recentwanted is not substituted by constructMessage() — the literal string stays in
     * the plain-text body. After the fix the macro must be replaced with only the Approved
     * message's formatted arrival date; Held/Rejected messages must not appear.
     *
     * This test FAILS on the current (buggy) code and will PASS only after the fix is applied.
     */
    public function testRecentWantedSubstitution(): void
    {
        // T1 = original arrival time for the Approved message.
        // T2 / T3 are arrival times for Held / Rejected messages — different days to T1.
        $t1 = '2024-03-01 09:00:00';
        $t2 = '2024-03-02 10:00:00';
        $t3 = '2024-03-03 11:00:00';

        $this->insertWantedMessage('WANTED: UT approved item',  'Approved', $t1);
        $this->insertWantedMessage('WANTED: UT held item',     'Pending',  $t2, $this->uid);
        $this->insertWantedMessage('WANTED: UT rejected item', 'Rejected', $t3);

        $a = new Admin($this->dbhr, $this->dbhm);

        $templateText = 'Hello member, recent wanteds: $recentwanted - thanks';

        $message = $a->constructMessage(
            'testgroup',
            'mods@testgroup.com',
            'member@test.com',
            'Test Member',
            'from@testgroup.com',
            'UT Test Admin',
            $templateText,
            [],
            null,
            null,
            true
        );

        $body = $message->getBody();

        // 1. After fix: $recentwanted must be fully substituted — no literal macro in body.
        $this->assertStringNotContainsString('$recentwanted', $body);

        // 2. The Approved message's original arrival date (T1) must appear.
        $tz = new \DateTimeZone('Europe/London');
        $dt1 = new \DateTime($t1, new \DateTimeZone('UTC'));
        $dt1->setTimezone($tz);
        $formattedT1 = $dt1->format('D, jS F');  // e.g. "Fri, 1st March"
        $this->assertStringContainsString($formattedT1, $body);

        // 3. Held and Rejected arrival dates must NOT appear (those messages are excluded).
        $dt2 = new \DateTime($t2, new \DateTimeZone('UTC'));
        $dt2->setTimezone($tz);
        $formattedT2 = $dt2->format('D, jS F');
        $this->assertStringNotContainsString($formattedT2, $body);

        $dt3 = new \DateTime($t3, new \DateTimeZone('UTC'));
        $dt3->setTimezone($tz);
        $formattedT3 = $dt3->format('D, jS F');
        $this->assertStringNotContainsString($formattedT3, $body);
    }
}
