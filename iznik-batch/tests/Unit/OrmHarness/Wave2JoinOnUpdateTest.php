<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: an ON-clause predicate, and an ordered/limited UPDATE.
 */
class Wave2JoinOnUpdateTest extends TestCase
{
    // SELECT m.emailfrequency, ... FROM messages_groups mg
    //   JOIN memberships m ON m.groupid = mg.groupid AND m.userid = ?
    //   WHERE mg.msgid = ? ORDER BY mg.arrival ASC LIMIT 1
    private const SITE_HOME_SETTINGS = 'c0ed26daf239';

    // UPDATE messages_attachments SET `primary` = 1 WHERE msgid = ? ORDER BY id ASC LIMIT 1
    private const SITE_PRIMARY_ATTACHMENT = 'd4d815c33ac9';

    /**
     * The userid predicate lives in the ON clause, not the WHERE. For an inner
     * join the rows are the same either way, but the clause it sits in is part
     * of the statement's meaning - and would stop being equivalent the moment
     * anyone changed this to a LEFT JOIN, where an ON predicate filters the
     * joined side and a WHERE predicate discards the row entirely. Keeping it
     * where the raw statement put it means that future change stays a
     * one-line decision rather than a silent behaviour flip.
     */
    public function test_home_group_settings(): void
    {
        GoldenSql::assert(self::SITE_HOME_SETTINGS, fn () => DB::table('messages_groups as mg')
            ->select('m.emailfrequency', 'm.eventsallowed', 'm.volunteeringallowed')
            ->join('memberships as m', function ($j) {
                $j->on('m.groupid', '=', 'mg.groupid')
                  ->where('m.userid', 1);
            })
            ->where('mg.msgid', 2)
            ->orderBy('mg.arrival')
            ->limit(1));
    }

    /**
     * ORDER BY + LIMIT on an UPDATE is a MySQL extension, and it is doing real
     * work here: of a message's attachments, mark the LOWEST-id one primary.
     * Drop the ORDER BY and you mark an arbitrary attachment; drop the LIMIT
     * and you mark them all. Laravel's MySQL grammar renders both.
     */
    public function test_mark_primary_attachment(): void
    {
        GoldenSql::assertUpdate(self::SITE_PRIMARY_ATTACHMENT, fn () => [
            DB::table('messages_attachments')->where('msgid', 1)->orderBy('id')->limit(1),
            ['primary' => 1],
        ]);
    }
}
