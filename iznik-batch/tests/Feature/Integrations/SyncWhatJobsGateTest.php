<?php

namespace Tests\Feature\Integrations;

use App\Services\WhatJobsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The feed-change gate: WhatJobs regenerates its feed about once a day but we sync six
 * times a day, so most runs reparse content already loaded. Skipping those runs saves
 * 25-45 minutes of batch-host CPU, ~1GB of Galera row images per node, and a spatial
 * jobs-index rebuild on each db host.
 *
 * What matters is that it never skips when it should not, so most of these tests are
 * about the ways the gate must fail open.
 */
class SyncWhatJobsGateTest extends TestCase
{
    use DatabaseTransactions;

    private function probe(array $canned): WhatJobsGateProbe
    {
        $probe = new WhatJobsGateProbe();
        $probe->canned = $canned;

        return $probe;
    }

    private function seedState(array $feeds, ?string $lastRebuild): void
    {
        $state = ['feeds' => $feeds];
        if ($lastRebuild !== null) {
            $state['last_rebuild'] = $lastRebuild;
        }

        DB::table('config')->upsert(
            [['key' => WhatJobsService::GATE_CONFIG_KEY, 'value' => json_encode($state)]],
            ['key'],
            ['value'],
        );
    }

    private function keyFor(string $url): string
    {
        return substr(hash('sha256', $url), 0, 16);
    }

    private function unchangedByHash(string $hash, string $path = '/tmp/whatjobs-test.xml'): array
    {
        return ['status' => 'unchanged', 'reason' => 'identical-content', 'path' => $path, 'hash' => $hash];
    }

    private function changed(string $hash, string $path = '/tmp/whatjobs-test.xml'): array
    {
        return ['status' => 'downloaded', 'reason' => 'changed', 'path' => $path, 'hash' => $hash];
    }

    // -----------------------------------------------------------------
    // Skipping
    // -----------------------------------------------------------------

    public function test_skips_when_the_content_hash_is_unchanged(): void
    {
        $this->seedState([$this->keyFor('feed1') => ['hash' => 'abc']], now()->subHour()->toDateTimeString());

        $gate = $this->probe(['feed1' => [$this->unchangedByHash('abc')]])->gate(['feed1'], false);

        $this->assertTrue($gate['skip']);
    }

    public function test_the_stored_hash_is_handed_to_the_next_fetch(): void
    {
        $this->seedState(
            [$this->keyFor('feed1') => ['etag' => 'W/"v1"', 'hash' => 'abc']],
            now()->subHour()->toDateTimeString(),
        );

        $probe = $this->probe(['feed1' => [$this->unchangedByHash('abc')]]);
        $probe->gate(['feed1'], false);

        $this->assertSame('abc', $probe->fetches[0]['prev']['hash']);
    }

    // -----------------------------------------------------------------
    // Failing open
    // -----------------------------------------------------------------

    public function test_runs_when_the_content_changed(): void
    {
        $this->seedState([$this->keyFor('feed1') => ['hash' => 'abc']], now()->subHour()->toDateTimeString());

        $gate = $this->probe(['feed1' => [$this->changed('def')]])->gate(['feed1'], false);

        $this->assertFalse($gate['skip']);
    }

    // One changed feed rebuilds the whole table, so every feed must run.
    public function test_runs_when_only_one_of_two_feeds_changed(): void
    {
        $this->seedState([
            $this->keyFor('feed1') => ['hash' => 'abc'],
            $this->keyFor('feed2') => ['hash' => 'xyz'],
        ], now()->subHour()->toDateTimeString());

        $gate = $this->probe([
            'feed1' => [$this->unchangedByHash('abc')],
            'feed2' => [$this->changed('new')],
        ])->gate(['feed1', 'feed2'], false);

        $this->assertFalse($gate['skip']);
    }

    public function test_runs_when_a_feed_download_failed(): void
    {
        $this->seedState([$this->keyFor('feed1') => ['hash' => 'abc']], now()->subHour()->toDateTimeString());

        $gate = $this->probe([
            'feed1' => [['status' => 'failed', 'reason' => 'http-429', 'path' => null]],
        ])->gate(['feed1'], false);

        $this->assertFalse($gate['skip']);
    }

    public function test_a_failed_fetch_does_not_overwrite_stored_validators(): void
    {
        $this->seedState([$this->keyFor('feed1') => ['hash' => 'abc']], now()->subHour()->toDateTimeString());

        $gate = $this->probe([
            'feed1' => [['status' => 'failed', 'reason' => 'http-429', 'path' => null]],
        ])->gate(['feed1'], false);

        $this->assertSame('abc', $gate['state']['feeds'][$this->keyFor('feed1')]['hash']);
    }

    public function test_runs_when_forced(): void
    {
        $this->seedState([$this->keyFor('feed1') => ['hash' => 'abc']], now()->subHour()->toDateTimeString());

        $probe = $this->probe(['feed1' => [$this->unchangedByHash('abc')]]);
        $probe->forceFullSync = true;

        $gate = $probe->gate(['feed1'], false);

        $this->assertFalse($gate['skip']);
        $this->assertSame('forced', $gate['bypass']);
        $this->assertSame([], $probe->fetches[0]['prev'], 'a forced run must not compare against the stored hash');
    }

    public function test_a_dry_run_always_parses(): void
    {
        $this->seedState([$this->keyFor('feed1') => ['hash' => 'abc']], now()->subHour()->toDateTimeString());

        $gate = $this->probe(['feed1' => [$this->unchangedByHash('abc')]])->gate(['feed1'], true);

        $this->assertFalse($gate['skip']);
        $this->assertSame('dry-run', $gate['bypass']);
    }

    public function test_runs_when_nothing_has_ever_been_rebuilt(): void
    {
        $this->seedState([$this->keyFor('feed1') => ['hash' => 'abc']], null);

        $gate = $this->probe(['feed1' => [$this->unchangedByHash('abc')]])->gate(['feed1'], false);

        $this->assertFalse($gate['skip']);
        $this->assertSame('no-previous-rebuild', $gate['bypass']);
    }

    // The floor that keeps the existing jobs.seenat freshness monitor meaningful, and
    // stops a gate bug turning into a permanent silent stall.
    public function test_forces_a_rebuild_once_the_last_one_is_too_old(): void
    {
        $this->seedState(
            [$this->keyFor('feed1') => ['hash' => 'abc']],
            now()->subHours(WhatJobsService::MAX_SKIP_HOURS + 1)->toDateTimeString(),
        );

        $gate = $this->probe(['feed1' => [$this->unchangedByHash('abc')]])->gate(['feed1'], false);

        $this->assertFalse($gate['skip']);
        $this->assertSame('rebuild-overdue', $gate['bypass']);
    }

    public function test_still_skips_just_inside_the_rebuild_floor(): void
    {
        $this->seedState(
            [$this->keyFor('feed1') => ['hash' => 'abc']],
            now()->subHours(WhatJobsService::MAX_SKIP_HOURS - 1)->toDateTimeString(),
        );

        $gate = $this->probe(['feed1' => [$this->unchangedByHash('abc')]])->gate(['feed1'], false);

        $this->assertTrue($gate['skip']);
    }

    public function test_unreadable_stored_state_falls_back_to_a_full_run(): void
    {
        DB::table('config')->upsert(
            [['key' => WhatJobsService::GATE_CONFIG_KEY, 'value' => 'not json']],
            ['key'],
            ['value'],
        );

        $gate = $this->probe(['feed1' => [$this->unchangedByHash('abc')]])->gate(['feed1'], false);

        $this->assertFalse($gate['skip']);
    }

    // -----------------------------------------------------------------
    // State bookkeeping
    // -----------------------------------------------------------------

    // A skipped run must not move last_rebuild, or the MAX_SKIP_HOURS floor would keep
    // resetting itself and never force the guaranteed rebuild.
    public function test_skipping_does_not_advance_the_last_rebuild_time(): void
    {
        $lastRebuild = now()->subHours(5)->toDateTimeString();
        $this->seedState([$this->keyFor('feed1') => ['hash' => 'abc']], $lastRebuild);

        $this->probe(['feed1' => [$this->unchangedByHash('abc')]])->gate(['feed1'], false);

        $stored = json_decode(DB::table('config')->where('key', WhatJobsService::GATE_CONFIG_KEY)->value('value'), true);

        $this->assertSame($lastRebuild, $stored['last_rebuild']);
        $this->assertNotEmpty($stored['last_checked'], 'a skipped run should still record that the gate ran');
    }

    public function test_committing_a_rebuild_stores_validators_and_advances_last_rebuild(): void
    {
        $this->seedState([], now()->subDay()->toDateTimeString());

        $probe = $this->probe([]);
        $probe->commit(['feeds' => [$this->keyFor('feed1') => ['hash' => 'newhash', 'etag' => 'W/"v2"']]]);

        $stored = json_decode(DB::table('config')->where('key', WhatJobsService::GATE_CONFIG_KEY)->value('value'), true);

        $this->assertSame('newhash', $stored['feeds'][$this->keyFor('feed1')]['hash']);
        $this->assertNotEmpty($stored['last_rebuild']);
        $this->assertGreaterThan(now()->subMinute()->timestamp, strtotime($stored['last_rebuild']));
    }

    // -----------------------------------------------------------------
    // Through sync() itself
    // -----------------------------------------------------------------

    // The skip path must return before analyseClickability/prepareTempTable/insertJobs,
    // which is the whole point - so this asserts the real sync() short-circuits.
    public function test_sync_returns_early_without_touching_the_jobs_table(): void
    {
        config(['freegle.whatjobs.feed1' => 'feed1', 'freegle.whatjobs.feed2' => null]);
        $this->seedState([$this->keyFor('feed1') => ['hash' => 'abc']], now()->subHour()->toDateTimeString());

        $before = DB::table('jobs')->count();

        $probe = $this->probe(['feed1' => [$this->unchangedByHash('abc')]]);
        $result = $probe->sync(false);

        $this->assertTrue($result['skipped_unchanged']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame($before, DB::table('jobs')->count());
        $this->assertFalse($probe->pipelineRan, 'the expensive pipeline must not have started');
    }
}

/**
 * Feeds canned fetch results to the gate so the tests never make HTTP calls, and exposes
 * the protected gate entry points.
 */
class WhatJobsGateProbe extends WhatJobsService
{
    /** @var array<string, array<int, array>> queued results per URL, consumed in order */
    public array $canned = [];

    /** @var array<int, array{url: string, prev: array}> */
    public array $fetches = [];

    /** Set if the run got past the gate into the expensive rebuild. */
    public bool $pipelineRan = false;

    public function gate(array $urls, bool $dryRun): array
    {
        return $this->evaluateFeedGate($urls, $dryRun);
    }

    public function commit(array $state): void
    {
        $this->commitFeedGateState($state);
    }

    public function analyseClickability(): void
    {
        $this->pipelineRan = true;
    }

    public function prepareTempTable(): void
    {
        $this->pipelineRan = true;
    }

    protected function fetchFeed(string $url, array $prev): array
    {
        $this->fetches[] = ['url' => $url, 'prev' => $prev];

        $queued = $this->canned[$url] ?? [];
        $next = array_shift($queued);
        $this->canned[$url] = $queued;

        return $next ?? ['status' => 'failed', 'reason' => 'no-canned-result', 'path' => null];
    }
}
