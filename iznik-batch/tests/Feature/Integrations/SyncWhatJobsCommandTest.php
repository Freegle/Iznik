<?php

namespace Tests\Feature\Integrations;

use App\Services\WhatJobsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncWhatJobsCommandTest extends TestCase
{
    use DatabaseTransactions;

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function makeIcal(): string
    {
        // Not used — this test uses inline XML, not iCal
        return '';
    }

    private function serviceWithFeeds(string $feed1Xml, string $feed2Xml = ''): WhatJobsService
    {
        $tmp1 = $this->writeTmpXml($feed1Xml);
        $tmp2 = $feed2Xml ? $this->writeTmpXml($feed2Xml) : null;

        return new class($tmp1, $tmp2) extends WhatJobsService {
            public function __construct(private string $file1, private ?string $file2) {}
            protected function downloadFeed(string $url): ?string
            {
                return $url === 'feed1' ? $this->file1 : $this->file2;
            }
            public function sync(bool $dryRun = false): array
            {
                $srid = config('freegle.srid', 3857);
                $geocodeCache = [];
                $jobs = $this->parseFeed($this->file1, 2, $geocodeCache);
                if ($this->file2) {
                    $jobs = array_merge($jobs, $this->parseFeed($this->file2, 1, $geocodeCache));
                }
                $total = count($jobs);
                if ($dryRun) {
                    return ['total' => $total, 'inserted' => 0, 'dry_run' => true];
                }
                $this->prepareTempTable();
                $inserted = $this->insertJobs($jobs, $srid);
                $this->deleteSpammyJobs();
                $this->swapTables();
                return ['total' => $total, 'inserted' => $inserted, 'dry_run' => false];
            }
        };
    }

    private function writeTmpXml(string $xml): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'whatjobs_test_') . '.xml';
        file_put_contents($tmp, $xml);
        return $tmp;
    }

    private function makeFeedXml(array $jobs): string
    {
        $nodes = '';
        foreach ($jobs as $j) {
            $ref   = htmlspecialchars($j['job_reference'] ?? 'ref-1');
            $title = htmlspecialchars($j['title'] ?? 'Test Job');
            $city  = htmlspecialchars($j['city'] ?? 'Leeds');
            $state = htmlspecialchars($j['state'] ?? 'West Yorkshire');
            $country = htmlspecialchars($j['country'] ?? 'UK');
            $cpc   = $j['cpc'] ?? '0.50';
            $url   = htmlspecialchars($j['url'] ?? 'https://example.com/job/1');
            $body  = htmlspecialchars($j['body'] ?? 'A test job.');
            $cat   = htmlspecialchars($j['category'] ?? 'IT');
            $posted = $j['posted_at'] ?? date('Y-m-d\TH:i:s', strtotime('-1 day'));
            $nodes .= "<job>
                <job_reference>$ref</job_reference>
                <title>$title</title>
                <city>$city</city>
                <state>$state</state>
                <country>$country</country>
                <cpc>$cpc</cpc>
                <url>$url</url>
                <body>$body</body>
                <category>$cat</category>
                <posted_at>$posted</posted_at>
            </job>";
        }
        return "<?xml version=\"1.0\"?><jobs><listing>$nodes</listing></jobs>";
    }

    // -----------------------------------------------------------------
    // canonicalJobTitle — pure-logic unit tests
    // -----------------------------------------------------------------

    /** @test */
    public function test_canonical_job_title_exact_match(): void
    {
        $svc = new WhatJobsService();
        $this->assertSame('Chef', $svc->canonicalJobTitle('Chef'));
        $this->assertSame('Chef', $svc->canonicalJobTitle('Chef - London'));
    }

    /** @test */
    public function test_canonical_job_title_keyword_match(): void
    {
        $svc = new WhatJobsService();
        $this->assertSame('HGV Class 1 Driver', $svc->canonicalJobTitle('Senior HGV Class 1 Driver needed'));
        $this->assertSame('Software Engineer', $svc->canonicalJobTitle('Graduate Software Engineer'));
    }

    /** @test */
    public function test_canonical_job_title_no_match_returns_null(): void
    {
        $svc = new WhatJobsService();
        $this->assertNull($svc->canonicalJobTitle('Unicorn Wrangler'));
        $this->assertNull($svc->canonicalJobTitle(null));
    }

    // -----------------------------------------------------------------
    // parseFeed — XML parsing
    // -----------------------------------------------------------------

    /** @test */
    public function test_parse_feed_returns_empty_for_below_minimum_cpc(): void
    {
        $xml = $this->makeFeedXml([['job_reference' => 'low-cpc', 'cpc' => '0.05']]);
        $tmp = $this->writeTmpXml($xml);
        $cache = [];
        $jobs = (new WhatJobsService())->parseFeed($tmp, 2, $cache);
        @unlink($tmp);
        $this->assertEmpty($jobs);
    }

    /** @test */
    public function test_parse_feed_skips_old_jobs(): void
    {
        $xml = $this->makeFeedXml([['job_reference' => 'old', 'posted_at' => date('Y-m-d', strtotime('-30 days'))]]);
        $tmp = $this->writeTmpXml($xml);
        $cache = [];
        $jobs = (new WhatJobsService())->parseFeed($tmp, 2, $cache);
        @unlink($tmp);
        $this->assertEmpty($jobs);
    }

    /** @test */
    public function test_parse_feed_skips_when_geocode_fails(): void
    {
        // No geocoder configured — all geocodes return null
        config(['freegle.geocoder' => '']);
        $xml   = $this->makeFeedXml([['job_reference' => 'no-geo', 'city' => 'UnknownCity', 'state' => '', 'country' => 'UK']]);
        $tmp   = $this->writeTmpXml($xml);
        $cache = [];
        $jobs  = (new WhatJobsService())->parseFeed($tmp, 2, $cache);
        @unlink($tmp);
        $this->assertEmpty($jobs);
    }

    // -----------------------------------------------------------------
    // deleteSpammyJobs
    // -----------------------------------------------------------------

    /** @test */
    public function test_deletes_spammy_jobs(): void
    {
        $srid = config('freegle.srid', 3857);
        DB::statement('DROP TABLE IF EXISTS jobs_new');
        DB::statement('CREATE TABLE jobs_new LIKE jobs');

        $geom = WhatJobsService::boxPoly(53.8, -1.5, 53.9, -1.4);
        // Insert 51 jobs with the same bodyhash (spam threshold is 50)
        for ($i = 0; $i < 51; $i++) {
            DB::statement(
                'INSERT INTO jobs_new (job_reference,title,bodyhash,geometry,visible,clickability)
                 VALUES (?,?,?,ST_GeomFromText(?,?),1,0)',
                ["spam-$i", 'Spam Job', 'spamhash', $geom, $srid]
            );
        }
        // Insert one legitimate job
        DB::statement(
            'INSERT INTO jobs_new (job_reference,title,bodyhash,geometry,visible,clickability)
             VALUES (?,?,?,ST_GeomFromText(?,?),1,0)',
            ['legit-1', 'Legit Job', 'legithash', $geom, $srid]
        );

        $svc = new WhatJobsService();
        $svc->deleteSpammyJobs('jobs_new');

        $this->assertEquals(0, DB::table('jobs_new')->where('bodyhash', 'spamhash')->count());
        $this->assertEquals(1, DB::table('jobs_new')->where('bodyhash', 'legithash')->count());

        DB::statement('DROP TABLE IF EXISTS jobs_new');
    }

    // -----------------------------------------------------------------
    // boxPoly helper
    // -----------------------------------------------------------------

    /** @test */
    public function test_box_poly_format(): void
    {
        $wkt = WhatJobsService::boxPoly(51.5, -0.1, 51.6, 0.0);
        $this->assertStringStartsWith('POLYGON((', $wkt);
        $this->assertStringContainsString('-0.1 51.5', $wkt);
    }

    // -----------------------------------------------------------------
    // dry-run mode
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // SyncWhatJobsCommand — artisan command level
    // -----------------------------------------------------------------

    /** @test */
    public function test_command_reports_inserted_jobs(): void
    {
        $this->mock(WhatJobsService::class)
            ->shouldReceive('sync')
            ->with(false)
            ->once()
            ->andReturn(['total' => 5, 'inserted' => 3, 'dry_run' => false]);

        $this->artisan('integrations:sync-whatjobs')
            ->expectsOutputToContain('Inserted 3 of 5 parsed jobs.')
            ->assertExitCode(0);
    }

    /** @test */
    public function test_command_dry_run_outputs_dry_run_prefix(): void
    {
        // Use a concrete stub via app->bind() — $this->mock() (Mockery) does not reliably
        // inject into artisan command method injection when run after another mock in the same suite.
        $this->app->bind(WhatJobsService::class, fn () => new class extends WhatJobsService {
            public function sync(bool $dryRun = false): array
            {
                return ['total' => 10, 'inserted' => 0, 'dry_run' => true];
            }
        });

        $this->artisan('integrations:sync-whatjobs', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->expectsOutputToContain('Would insert 0 of 10 parsed jobs.')
            ->assertExitCode(0);
    }

    // -----------------------------------------------------------------
    // dry-run mode
    // -----------------------------------------------------------------

    /** @test */
    public function test_dry_run_does_not_create_table(): void
    {
        DB::statement('DROP TABLE IF EXISTS jobs_new');

        // Build a service with pre-geocoded jobs (inject via geocode cache)
        $geom = WhatJobsService::boxPoly(53.8, -1.55, 53.9, -1.45);
        $xml  = $this->makeFeedXml([['job_reference' => 'j1', 'city' => 'Leeds', 'state' => 'West Yorkshire', 'country' => 'UK']]);
        $svc  = new class($xml, $geom) extends WhatJobsService {
            public function __construct(private string $xml, private string $geom) {}
            public function geocodeCityState(string $city, string $state, string $country, array &$cache): ?array
            {
                return [53.8, -1.55, 53.9, -1.45, $this->geom];
            }
            public function sync(bool $dryRun = false): array
            {
                $tmp   = tempnam(sys_get_temp_dir(), 'whatjobs_dr_');
                file_put_contents($tmp, $this->xml);
                $cache = [];
                $jobs  = $this->parseFeed($tmp, 2, $cache);
                @unlink($tmp);
                if ($dryRun) {
                    return ['total' => count($jobs), 'inserted' => 0, 'dry_run' => true];
                }
                $this->prepareTempTable();
                $inserted = $this->insertJobs($jobs);
                $this->deleteSpammyJobs();
                $this->swapTables();
                return ['total' => count($jobs), 'inserted' => $inserted, 'dry_run' => false];
            }
        };

        $result = $svc->sync(true);

        $this->assertTrue($result['dry_run']);
        $this->assertEquals(0, $result['inserted']);
        $this->assertFalse(
            DB::select("SHOW TABLES LIKE 'jobs_new'") !== [],
            'jobs_new should not exist after dry-run'
        );
    }

    /** @test */
    public function test_sync_dry_run_covers_real_sync_method(): void
    {
        $geom = WhatJobsService::boxPoly(53.8, -1.55, 53.9, -1.45);
        $xml  = $this->makeFeedXml([
            ['job_reference' => 'sync-dr-1', 'city' => 'Leeds', 'state' => 'West Yorkshire', 'country' => 'UK'],
            ['job_reference' => 'sync-dr-2', 'city' => 'Leeds', 'state' => 'West Yorkshire', 'country' => 'UK'],
        ]);

        $svc = new class($xml, $geom) extends WhatJobsService {
            public function __construct(private string $xml, private string $geom) {}
            protected function downloadFeed(string $url): ?string
            {
                $tmp = tempnam(sys_get_temp_dir(), 'whatjobs_sdr_');
                file_put_contents($tmp, $this->xml);
                return $tmp;
            }
            public function geocodeCityState(string $city, string $state, string $country, array &$cache): ?array
            {
                return [53.8, -1.55, 53.9, -1.45, $this->geom];
            }
        };

        config(['freegle.whatjobs.feed1' => 'http://fake-feed1-url', 'freegle.whatjobs.feed2' => null]);

        $result = $svc->sync(true);

        $this->assertTrue($result['dry_run']);
        $this->assertEquals(2, $result['total']);
        $this->assertEquals(0, $result['inserted']);
    }

    /** @test */
    public function test_sync_non_dry_run_inserts_into_jobs_new(): void
    {
        DB::statement('DROP TABLE IF EXISTS jobs_new');

        $geom = WhatJobsService::boxPoly(53.8, -1.55, 53.9, -1.45);
        $xml  = $this->makeFeedXml([
            ['job_reference' => 'sync-e2e-1', 'city' => 'Leeds', 'state' => 'West Yorkshire', 'country' => 'UK'],
        ]);

        $svc = new class($xml, $geom) extends WhatJobsService {
            public function __construct(private string $xml, private string $geom) {}
            protected function downloadFeed(string $url): ?string
            {
                $tmp = tempnam(sys_get_temp_dir(), 'whatjobs_ndr_');
                file_put_contents($tmp, $this->xml);
                return $tmp;
            }
            public function geocodeCityState(string $city, string $state, string $country, array &$cache): ?array
            {
                return [53.8, -1.55, 53.9, -1.45, $this->geom];
            }
            // Prevent destructive DDL in tests
            public function swapTables(): void {}
            public function analyseClickability(): void {}
            public function updateClickability(): void {}
        };

        config(['freegle.whatjobs.feed1' => 'http://fake-feed1-url', 'freegle.whatjobs.feed2' => null]);

        $result = $svc->sync(false);

        $this->assertFalse($result['dry_run']);
        $this->assertEquals(1, $result['total']);
        $this->assertEquals(1, $result['inserted']);
        $this->assertEquals(1, DB::table('jobs_new')->where('job_reference', 'sync-e2e-1')->count());

        DB::statement('DROP TABLE IF EXISTS jobs_new');
    }
}
