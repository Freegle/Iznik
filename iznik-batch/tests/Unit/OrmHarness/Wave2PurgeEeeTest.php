<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 2: the EEE classification index write and the orphaned-log count.
 */
class Wave2PurgeEeeTest extends TestCase
{
    // INSERT IGNORE INTO eee_classified_attachments (messageid, attid) VALUES (?, ?)
    private const SITE_EEE_INDEX = 'ddd161897749';

    // SELECT COUNT(*) AS cnt FROM logs LEFT JOIN messages ... WHERE messages.id IS NULL ...
    private const SITE_ORPHAN_LOGS = '7d7392116e11';

    public function test_eee_index_write(): void
    {
        GoldenSql::assertInsertOrIgnore(self::SITE_EEE_INDEX, fn () => [
            DB::table('eee_classified_attachments'),
            ['messageid' => 1, 'attid' => 2],
        ]);
    }

    /**
     * An anti-join counting log rows whose message has been deleted. leftJoin
     * plus messages.id IS NULL - an inner join counts the exact opposite set,
     * logs whose message still exists, and this figure drives what a purge run
     * reports it is about to remove.
     */
    public function test_orphaned_log_count(): void
    {
        GoldenSql::assert(self::SITE_ORPHAN_LOGS, function () {
            $q = DB::table('logs')
                ->leftJoin('messages', 'messages.id', '=', 'logs.msgid')
                ->whereNotNull('logs.msgid')
                ->whereNull('messages.id')
                ->where('logs.timestamp', '>=', '2026-01-01')
                ->where('logs.timestamp', '<', '2026-02-01');
            $q->aggregate = ['function' => 'count', 'columns' => ['*']];

            return $q;
        });
    }
}
