<?php

namespace Tests\Feature\OrmHarness;

use App\Services\PurgeService;
use App\Services\Ripple\ExpandService;
use App\Services\Ripple\ReachService;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\EngineParity;
use Tests\TestCase;

/**
 * Layer 2 of the parity harness for the Laravel pilot sites: the same call gives the same rows on
 * MySQL and on PostgreSQL. Skipped when PostgreSQL is not reachable; PG_ENGINE=1 turns the skip into
 * a failure. Ids start at EngineParity::id(5000) so these tests keep clear of other tests' rows.
 * docs/developers/reference/parity-harness.md says how to add a site.
 */
class PilotEngineParityTest extends TestCase
{
    private function fid(int $n): int
    {
        return EngineParity::id(5000 + $n);
    }

    private function logRow(int $n, array $more): array
    {
        return ['id' => $this->fid($n), 'timestamp' => '2026-01-10 09:00:00'] + $more;
    }

    /**
     * logs.user is a word PostgreSQL reserves. The builder quotes it, and the raw statement did not.
     * The member left group 1 after a ripple-join, so the group is blocked; group 2 was joined by hand
     * and left, so it is not.
     */
    public function test_ripple_opt_out_group_ids(): void
    {
        $uid = $this->fid(1);
        $fixture = [
            EngineParity::table('users', [['id' => $uid], ['id' => $this->fid(2)]]),
            EngineParity::table('logs', [
                $this->logRow(10, ['type' => 'Group', 'subtype' => 'Joined', 'groupid' => 11, 'user' => $uid, 'text' => 'Rippled']),
                $this->logRow(11, ['type' => 'Group', 'subtype' => 'Left', 'groupid' => 11, 'user' => $uid]),
                $this->logRow(12, ['type' => 'Group', 'subtype' => 'Joined', 'groupid' => 12, 'user' => $uid, 'text' => 'Manual']),
                $this->logRow(13, ['type' => 'Group', 'subtype' => 'Left', 'groupid' => 12, 'user' => $uid]),
                $this->logRow(14, ['type' => 'User', 'subtype' => 'Left', 'groupid' => 13, 'user' => $uid]),
                $this->logRow(15, ['type' => 'Group', 'subtype' => 'Left', 'groupid' => 14, 'user' => $this->fid(2)]),
            ]),
        ];

        EngineParity::assert('8f86a2ed92d0', $fixture, function () use ($uid) {
            $method = new \ReflectionMethod(ExpandService::class, 'rippleOptOutGroupIds');

            return $method->invoke(new ExpandService(new ReachService()), $uid);
        }, expect: [11]);
    }

    /**
     * The poster's organic membership comes before a ripple-created one, and the newest of the
     * organic ones wins: group 3.
     */
    public function test_organic_membership_settings(): void
    {
        $uid = $this->fid(1);
        $group = fn (int $n) => ['id' => $this->fid($n), 'polyindex' => EngineParity::geometry('POINT(-3.2 55.9)')];
        $fixture = [
            EngineParity::table('users', [['id' => $uid]]),
            EngineParity::table('groups', [$group(21), $group(22), $group(23)]),
            EngineParity::table('memberships', [
                ['id' => $this->fid(30), 'userid' => $uid, 'groupid' => $this->fid(21), 'rippled' => 1, 'emailfrequency' => -1, 'added' => '2026-01-03 00:00:00'],
                ['id' => $this->fid(31), 'userid' => $uid, 'groupid' => $this->fid(22), 'rippled' => 0, 'emailfrequency' => 24, 'added' => '2026-01-01 00:00:00'],
                ['id' => $this->fid(32), 'userid' => $uid, 'groupid' => $this->fid(23), 'rippled' => 0, 'emailfrequency' => 12, 'added' => '2026-01-02 00:00:00'],
            ]),
        ];

        EngineParity::assert('c24c68341f84', $fixture, fn () => ExpandService::organicMembership($uid),
            expect: ['emailfrequency' => 12, 'eventsallowed' => 1, 'volunteeringallowed' => 1]);
    }

    /**
     * Two logs name a message that does not exist, one inside the window and one outside it, and one
     * names a message that does.
     */
    public function test_orphaned_message_log_count(): void
    {
        $uid = $this->fid(1);
        $fixture = [
            EngineParity::table('users', [['id' => $uid]]),
            EngineParity::table('messages', [['id' => $this->fid(40), 'fromuser' => $uid, 'message' => 'x']]),
            EngineParity::table('logs', [
                $this->logRow(50, ['type' => 'Message', 'subtype' => 'Received', 'msgid' => $this->fid(41), 'timestamp' => '2026-01-10 09:00:00']),
                $this->logRow(51, ['type' => 'Message', 'subtype' => 'Received', 'msgid' => $this->fid(42), 'timestamp' => '2026-01-20 09:00:00']),
                $this->logRow(52, ['type' => 'Message', 'subtype' => 'Received', 'msgid' => $this->fid(43), 'timestamp' => '2025-06-01 09:00:00']),
                $this->logRow(53, ['type' => 'Message', 'subtype' => 'Received', 'msgid' => $this->fid(40), 'timestamp' => '2026-01-10 09:00:00']),
            ]),
        ];

        EngineParity::assert('be29b29dc957', $fixture,
            fn () => PurgeService::countOrphanedMessageLogs('2026-01-01 00:00:00', '2026-02-01 00:00:00'),
            expect: 2);
    }

    /**
     * A write site returns the rows it left behind: the other two logs stay.
     */
    public function test_delete_log(): void
    {
        $fixture = [
            EngineParity::table('logs', [
                $this->logRow(60, ['type' => 'Message', 'subtype' => 'Received']),
                $this->logRow(61, ['type' => 'Message', 'subtype' => 'Received']),
                $this->logRow(62, ['type' => 'Message', 'subtype' => 'Received']),
            ]),
        ];

        // The same statement is two sites, one in each purge.
        foreach (['29a8e9d8e68d', 'a8a7d546e8d4'] as $siteId) {
            EngineParity::assert($siteId, $fixture, function () {
                PurgeService::deleteLog($this->fid(61));

                return DB::table('logs')->whereBetween('id', [$this->fid(60), $this->fid(62)])->orderBy('id')->pluck('id')->all();
            }, ordered: true, expect: [$this->fid(60), $this->fid(62)]);
        }
    }
}
