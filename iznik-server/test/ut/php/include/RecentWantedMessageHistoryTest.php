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
class RecentWantedMessageHistoryTest extends IznikTestCase
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

        $this->dbhm->preExec("DELETE FROM messages WHERE subject LIKE 'WANTED: UT RW %';");

        list($g, $this->gid) = $this->createTestGroup('testgroup', Group::GROUP_FREEGLE);
        $g->setPrivate('onhere', 1);

        list($user, $this->uid) = $this->createTestUserWithLogin('Test User RW', 'testpw');
        $user->addMembership($this->gid);
    }

    protected function tearDown(): void
    {
        $this->dbhm->preExec("DELETE FROM messages WHERE subject LIKE 'WANTED: UT RW %';");
        parent::tearDown();
    }

    private function insertWanted(string $subject, string $collection, string $arrival): int
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

        return $msgid;
    }

    /**
     * AssertFlip STEP 2 (inverted): asserts the CORRECT behaviour that must hold after the fix.
     *
     * Bug: the Go API GetUserMessageHistory has no collection='Approved' filter, so Rejected and
     * Pending messages appear in messagehistory and therefore in the $recentwanted substitution.
     * The PHP layer also passes PENDING in the modtools collection list (Message.php ~line 1544).
     *
     * To simulate the bug in PHP we call getPublicsById with [APPROVED, REJECTED], replicating
     * what the un-filtered Go API returns.  The correct behaviour is that only Approved messages
     * appear in messagehistory regardless of what collection list is supplied to the API.
     *
     * This test FAILS on the current (buggy) code and will PASS only after the fix is applied.
     */
    public function testRecentWantedExcludesNonApproved(): void
    {
        // Use recent dates so the default 30-day history window includes them.
        $t1 = date('Y-m-d H:i:s', strtotime('-10 days'));  // APPROVED message
        $t2 = date('Y-m-d H:i:s', strtotime('-7 days'));   // REJECTED message

        $this->insertWanted('WANTED: UT RW approved item',  MessageCollection::APPROVED, $t1);
        $this->insertWanted('WANTED: UT RW rejected item',  MessageCollection::REJECTED, $t2);

        $u = new User($this->dbhr, $this->dbhm);

        // Simulate the Go API's no-filter bug: pass both Approved AND Rejected to PHP.
        // After the fix, messagehistory must still only contain Approved messages.
        $rets = $u->getPublicsById(
            [$this->uid],
            NULL,
            TRUE,    // $history
            FALSE,   // $comments
            FALSE,   // $memberof
            FALSE,   // $applied
            FALSE,   // $modmailsonly
            FALSE,   // $emailhistory
            [MessageCollection::APPROVED, MessageCollection::REJECTED],  // broken: includes Rejected
            FALSE    // $historyfull — use 30-day window; test dates are within that window
        );

        $history = $rets[$this->uid]['messagehistory'] ?? [];
        $subjects = array_column($history, 'subject');
        $collections = array_column($history, 'collection');

        // 1. The Approved message must appear in history.
        $this->assertContains('WANTED: UT RW approved item', $subjects,
            'Approved WANTED must appear in messagehistory');

        // 2. After fix: the Rejected message must NOT appear in history.
        //    This assertion FAILS on current code because PHP honours the [APPROVED,REJECTED]
        //    collection param and returns Rejected messages; only passes after the fix ensures
        //    that only Approved messages are ever included in messagehistory.
        $this->assertNotContains('WANTED: UT RW rejected item', $subjects,
            'Rejected WANTED must NOT appear in messagehistory (only Approved posts belong in $recentwanted)');

        // 3. No non-Approved collection should appear in history at all.
        foreach ($collections as $coll) {
            $this->assertSame(
                MessageCollection::APPROVED,
                $coll,
                "messagehistory must contain only Approved messages; found collection '$coll'"
            );
        }
    }
}
