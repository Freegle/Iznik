<?php

namespace Tests\Feature\Monitor;

use App\Monitoring\OutcomeCheck;
use App\Monitoring\OutcomeResult;
use App\Monitoring\ScheduledOutcomeRegistry;
use App\Models\MessageGroup;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Smoke tests for the populated registry.
 *
 * The most important guarantee here is that every registered check actually
 * QUERIES THE REAL SCHEMA without error — a typo'd table or column name would
 * throw a query exception against the migrated test DB and fail this test,
 * rather than silently false-alarming in production. (Each check legitimately
 * BREACHES against empty tables; we assert it returns a valid result, not that
 * it passes.)
 */
class ScheduledOutcomeRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registry_returns_checks(): void
    {
        $checks = (new ScheduledOutcomeRegistry())->checks();

        $this->assertNotEmpty($checks);
        foreach ($checks as $check) {
            $this->assertInstanceOf(OutcomeCheck::class, $check);
        }
    }

    public function test_registry_slugs_are_unique(): void
    {
        $slugs = array_map(fn (OutcomeCheck $c) => $c->slug(), (new ScheduledOutcomeRegistry())->checks());

        $this->assertSame(
            array_values(array_unique($slugs)),
            array_values($slugs),
            'Registry slugs must be unique'
        );
    }

    public function test_every_check_evaluates_against_real_schema_without_error(): void
    {
        // Mid-morning so daytime-windowed checks are active and actually run a query.
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 14, 0, 0));

        foreach ((new ScheduledOutcomeRegistry())->checks() as $check) {
            $result = $check->evaluate(Carbon::now());
            $this->assertInstanceOf(
                OutcomeResult::class,
                $result,
                "Check {$check->slug()} did not return an OutcomeResult"
            );
        }
    }

    /**
     * The daily-digest check is SKIPPED by default (empty allowlist), so the
     * smoke test above never exercises its users_digests query. Enable it and
     * seed a row to verify the table/column wiring is correct.
     */
    public function test_daily_digest_check_queries_users_digests_when_enabled(): void
    {
        config(['freegle.digest.daily_allowlist' => 'pilot@example.com']);
        // 14:00 UTC = 15:00 London — inside the digest check's 13:00-24:00 window.
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 14, 0, 0));

        $check = null;
        foreach ((new ScheduledOutcomeRegistry())->checks() as $c) {
            if ($c->slug() === 'mail:digest:unified --mode=daily') {
                $check = $c;
            }
        }
        $this->assertNotNull($check, 'daily digest check not found in registry');

        // No daily send recorded today → breach (query runs against users_digests).
        DB::table('users_digests')->where('mode', 'daily')->delete();
        $this->assertTrue($check->evaluate(Carbon::now())->isBreach());

        // A daily send recorded today → OK.
        $user = $this->createTestUser();
        DB::table('users_digests')->insert([
            'userid' => $user->id,
            'mode' => 'daily',
            'lastsent' => Carbon::now(),
        ]);
        $this->assertTrue($check->evaluate(Carbon::now())->isOk());
    }

    private function check(string $slug): \App\Monitoring\OutcomeCheck
    {
        foreach ((new ScheduledOutcomeRegistry())->checks() as $c) {
            if ($c->slug() === $slug) {
                return $c;
            }
        }
        $this->fail("check '{$slug}' not found in registry");
    }

    /**
     * The config-freshness checks read a timestamp embedded in a config value
     * (the config table has no updated_at). Verify the parse + compare for both
     * the unix-timestamp (git-summary) and JSON-updated_at (cpi) formats.
     */
    public function test_config_freshness_checks_parse_and_evaluate(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 14, 0, 0));
        DB::table('config')->whereIn('key', ['git_summary_last_run', 'cpi_annual_data'])->delete();

        $git = $this->check('data:git-summary');
        $cpi = $this->check('data:update-cpi');

        // Missing key -> skipped (no false breach on a fresh deploy).
        $this->assertTrue($git->evaluate(Carbon::now())->isSkipped());

        // git-summary: fresh unix timestamp -> ok; stale -> breach.
        DB::table('config')->insert(['key' => 'git_summary_last_run', 'value' => (string) Carbon::now()->subDays(2)->timestamp]);
        $this->assertTrue($git->evaluate(Carbon::now())->isOk());
        DB::table('config')->where('key', 'git_summary_last_run')->update(['value' => (string) Carbon::now()->subDays(30)->timestamp]);
        $this->assertTrue($git->evaluate(Carbon::now())->isBreach());

        // cpi: fresh ISO-8601 updated_at inside JSON -> ok; stale -> breach.
        DB::table('config')->insert([
            'key' => 'cpi_annual_data',
            'value' => json_encode(['data' => [], 'updated_at' => Carbon::now()->subDays(5)->toIso8601String()]),
        ]);
        $this->assertTrue($cpi->evaluate(Carbon::now())->isOk());
        DB::table('config')->where('key', 'cpi_annual_data')->update([
            'value' => json_encode(['data' => [], 'updated_at' => Carbon::now()->subDays(60)->toIso8601String()]),
        ]);
        $this->assertTrue($cpi->evaluate(Carbon::now())->isBreach());
    }

    /**
     * The whatjobs check is gated on a feed being configured, so the smoke test
     * skips it. Enable it and verify it queries jobs.seenat against the real
     * schema (empty table -> breach, but the query must run without error).
     */
    public function test_whatjobs_check_queries_jobs_table_when_enabled(): void
    {
        config(['freegle.whatjobs.feed1' => 'https://example.com/feed']);
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 14, 0, 0));
        DB::table('jobs')->delete();

        $result = $this->check('integrations:sync-whatjobs')->evaluate(Carbon::now());

        // No rows in jobs -> breach ("no rows"), proving the seenat query ran.
        $this->assertTrue($result->isBreach(), $result->message);
    }

    /**
     * The contentcheck worker deliberately skips held-by-a-mod rows
     * (->whereNull('mg.heldby')): a held post is pulled back for review and is
     * never auto-checked until the mod releases it, so it can sit indefinitely
     * without ever getting contentcheck_checked_at stamped. The backlog check
     * must mirror that skip, otherwise every held post false-alarms as a stalled
     * moderation pipeline. A non-held stale post, by contrast, must still breach.
     */
    public function test_contentcheck_check_ignores_held_pending_but_flags_unheld(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 12, 14, 0, 0));

        $group = $this->createTestGroup();
        $user  = $this->createTestUser();

        $seedStalePending = function (?int $heldby) use ($group, $user): void {
            $msgid = DB::table('messages')->insertGetId([
                'fromuser' => $user->id,
                'type'     => 'Offer',
                'subject'  => 'OFFER: Stale post',
                'textbody' => 'A stale post. Collection only.',
                'message'  => 'A stale post. Collection only.',
                'arrival'  => Carbon::now()->subHour(),
                'date'     => Carbon::now()->subHour(),
                'source'   => 'Platform',
            ]);
            DB::table('messages_groups')->insert([
                'msgid'                   => $msgid,
                'groupid'                 => $group->id,
                'collection'              => MessageGroup::COLLECTION_PENDING,
                'arrival'                 => Carbon::now()->subHour(),
                'deleted'                 => 0,
                'heldby'                  => $heldby,
                'contentcheck_checked_at' => null,
            ]);
        };

        $check = $this->check('messages:contentcheck');

        // Only a held stale post exists -> the worker skips it, so no breach.
        $seedStalePending($user->id);
        $this->assertTrue(
            $check->evaluate(Carbon::now())->isOk(),
            'A held Pending post must not count as a contentcheck backlog'
        );

        // Add an unheld stale post -> worker would have checked it, so breach.
        $seedStalePending(null);
        $this->assertTrue(
            $check->evaluate(Carbon::now())->isBreach(),
            'An unheld stale Pending post must breach the contentcheck backlog'
        );
    }
}
