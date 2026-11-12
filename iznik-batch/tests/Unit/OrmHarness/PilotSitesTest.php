<?php

namespace Tests\Unit\OrmHarness;

use App\Services\PurgeService;
use App\Services\Ripple\ExpandService;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Layer 1 of the parity harness for the Laravel pilot sites: each converted query renders the SQL
 * the raw statement had. The queries are built by the services themselves, so these tests render
 * the statements that run. docs/developers/reference/parity-harness.md says how to add a site.
 */
class PilotSitesTest extends TestCase
{
    /**
     * The raw statement wrote 'Group', 'Joined' and 'Left' in the text. The builder binds them, so
     * the statement has four placeholders where the golden had one. The reviewer recorded that as
     * the approved diff for this site in tools/orm-migration/manifest.json.
     */
    public function test_opt_out_log_query(): void
    {
        GoldenSql::assert('8f86a2ed92d0', fn () => ExpandService::optOutLogQuery(5));
    }

    /**
     * first() is take(1), so the golden's LIMIT 1 is the same statement. The test adds it because
     * it renders the query without running it.
     */
    public function test_organic_membership_query(): void
    {
        GoldenSql::assert('c24c68341f84', fn () => ExpandService::organicMembershipQuery(5)->limit(1));
    }

    public function test_orphaned_message_log_count(): void
    {
        GoldenSql::assertCount('be29b29dc957', fn () => PurgeService::orphanedMessageLogQuery('2026-01-01 00:00:00', '2026-02-01 00:00:00'));
    }

    /**
     * Two sites wrote this statement, one per purge. Converting only one would renumber the
     * other, because a site's id counts the identical statements before it in the file, so both
     * are converted and proven together.
     */
    public function test_delete_log(): void
    {
        foreach (['29a8e9d8e68d', 'a8a7d546e8d4'] as $siteId) {
            GoldenSql::assertDelete($siteId, fn () => PurgeService::deleteLogQuery(5));
        }
    }
}
