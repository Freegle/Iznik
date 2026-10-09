<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: ExpandService's ripple membership-backfill target selection.
 *
 * The most structurally involved conversion in this migration so far, and the
 * one where getting it wrong is least visible: this decides which groups a
 * poster gets auto-joined to. Three guards, one of them nested two deep.
 *
 * The inner pair inside the second guard is the subtle part. NOT EXISTS(lj2)
 * pins lj to the poster's MOST RECENT 'Joined' log for that group; EXISTS(ll)
 * then requires a 'Left' after it. Together they mean "the last time they
 * joined this group it was a ripple, and they have since left" - i.e. do not
 * drag them back in. Flatten either one and the question degrades to "there
 * exists some rippled join, and some leave", which would re-join people who
 * left and then deliberately rejoined.
 */
class Wave2RippleTargetsTest extends TestCase
{
    private const SITE_RIPPLE_TARGETS = 'bfe55685accb';

    public function test_membership_backfill_targets(): void
    {
        GoldenSql::assert(self::SITE_RIPPLE_TARGETS, fn () => DB::table('messages_groups as mg')
            ->select('mg.groupid')
            ->where('mg.msgid', 1)
            ->where('mg.rippled_in', 1)
            ->whereNotExists(fn ($q) => $q->from('memberships as m')
                ->where('m.userid', 2)
                ->whereColumn('m.groupid', 'mg.groupid'))
            ->whereNotExists(fn ($q) => $q->from('logs as lj')
                ->where('lj.user', 2)
                ->whereColumn('lj.groupid', 'mg.groupid')
                ->where('lj.type', 'Group')
                ->where('lj.subtype', 'Joined')
                ->where('lj.text', 'Rippled')
                ->whereNotExists(fn ($q2) => $q2->from('logs as lj2')
                    ->whereColumn('lj2.user', 'lj.user')
                    ->whereColumn('lj2.groupid', 'lj.groupid')
                    ->where('lj2.type', 'Group')
                    ->where('lj2.subtype', 'Joined')
                    ->whereColumn('lj2.id', '>', 'lj.id'))
                ->whereExists(fn ($q2) => $q2->from('logs as ll')
                    ->whereColumn('ll.user', 'lj.user')
                    ->whereColumn('ll.groupid', 'lj.groupid')
                    ->where('ll.type', 'Group')
                    ->where('ll.subtype', 'Left')
                    ->whereColumn('ll.id', '>', 'lj.id')))
            ->whereNotExists(fn ($q) => $q->from('users_banned as ub')
                ->where('ub.userid', 2)
                ->whereColumn('ub.groupid', 'mg.groupid')));
    }
}
