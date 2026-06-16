<?php

namespace Tests\Feature\Integrations;

use App\Services\ReachVolunteeringService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncReachVolunteeringCommandTest extends TestCase
{
    // ── Command tests ─────────────────────────────────────────────────────────

    public function test_runs_cleanly_with_empty_feed(): void
    {
        $mock = $this->createMock(ReachVolunteeringService::class);
        $mock->method('sync')->willReturn(['added' => 0, 'updated' => 0, 'deleted' => 0]);
        $this->app->instance(ReachVolunteeringService::class, $mock);

        $this->artisan('integrations:sync-reachvolunteering')
            ->expectsOutputToContain('Processed 0 new, 0 updated, 0 deleted.')
            ->assertExitCode(0);
    }

    public function test_dry_run_outputs_dry_run_prefix(): void
    {
        $mock = $this->createMock(ReachVolunteeringService::class);
        $mock->method('sync')->with(true)->willReturn(['added' => 3, 'updated' => 1, 'deleted' => 2]);
        $this->app->instance(ReachVolunteeringService::class, $mock);

        $this->artisan('integrations:sync-reachvolunteering', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Would process 3 new, 1 updated, 2 deleted.')
            ->assertExitCode(0);
    }

    // ── Service tests ─────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Don't sleep between retries in tests.
        config(['freegle.reach_volunteering.retry_delay_seconds' => 0]);
    }

    private function makeService(array $feedData): ReachVolunteeringService
    {
        return $this->makeServiceFromBody(json_encode($feedData));
    }

    private function makeServiceFromBody(string $body): ReachVolunteeringService
    {
        return $this->makeServiceFromBodies([$body]);
    }

    /**
     * Build a service that returns each body in sequence on successive feed
     * fetches (the last body repeats if fetched more times than provided).
     */
    private function makeServiceFromBodies(array $bodies): ReachVolunteeringService
    {
        return new class($bodies) extends ReachVolunteeringService {
            private int $call = 0;

            public function __construct(private array $bodies) {}

            protected function fetchFeedData(string $feedUrl): string
            {
                $body = $this->bodies[$this->call] ?? end($this->bodies);
                $this->call++;

                return $body;
            }
        };
    }

    private function opportunity(array $overrides = []): array
    {
        return array_merge([
            'job_id'             => '12345',
            'title'              => 'Test Volunteer Role',
            'date_posted'        => now()->format('Y-m-d'),
            'summary'            => 'Summary text',
            'description'        => '<p>Help with something great.</p>',
            'person_description' => 'Enthusiastic',
            'person_impact'      => 'Big impact',
            'other_details'      => 'Flexible hours',
            'location'           => 'City Centre, SW1A 1AA, United Kingdom',
            'skills'             => 'Communication',
            'organisation'       => 'Test Charity',
            'causes'             => 'Environment',
            'activities'         => 'Fundraising',
            'objectives'         => 'Help people',
            'url'                => 'https://reachvolunteering.org.uk/vol/12345',
        ], $overrides);
    }

    private function seedLocationAndGroup(string $postcode, float $lat, float $lng): object
    {
        $canon = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $postcode));
        DB::table('locations')->insert([
            'name'  => $postcode,
            'canon' => $canon,
            'type'  => 'Postcode',
            'lat'   => $lat,
            'lng'   => $lng,
        ]);

        return $this->createTestGroup([
            'lat'      => $lat,
            'lng'      => $lng,
            'publish'  => 1,
            'listable' => 1,
        ]);
    }

    public function test_creates_volunteering_record_for_nearby_group(): void
    {
        $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        $result = $this->makeService([$this->opportunity()])->sync();

        $this->assertSame(1, $result['added']);
        $this->assertDatabaseHas('volunteering', [
            'externalid' => 'reach-12345',
            'title'      => 'Test Volunteer Role',
        ]);
    }

    public function test_strips_html_from_description(): void
    {
        $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        $this->makeService([$this->opportunity([
            'description' => '<p>Help <strong>today</strong>.</p>',
        ])])->sync();

        $record = DB::table('volunteering')->where('externalid', 'reach-12345')->first();
        $this->assertStringNotContainsString('<p>', $record->description);
        $this->assertStringContainsStringIgnoringCase('Help today', $record->description);
    }

    public function test_prepends_organisation_to_description(): void
    {
        $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        $this->makeService([$this->opportunity([
            'organisation' => 'Acme Charity',
            'description'  => 'Do great things.',
        ])])->sync();

        $record = DB::table('volunteering')->where('externalid', 'reach-12345')->first();
        $this->assertStringContainsString('Posted by Acme Charity', $record->description);
    }

    public function test_strips_country_suffix_from_location(): void
    {
        $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        $this->makeService([$this->opportunity([
            'location' => 'Westminster, SW1A 1AA, United Kingdom',
        ])])->sync();

        $record = DB::table('volunteering')->where('externalid', 'reach-12345')->first();
        $this->assertStringNotContainsString('United Kingdom', $record->location);
    }

    public function test_links_volunteering_to_group(): void
    {
        $group = $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        $this->makeService([$this->opportunity()])->sync();

        $vid = DB::table('volunteering')->where('externalid', 'reach-12345')->value('id');
        $this->assertDatabaseHas('volunteering_groups', [
            'volunteeringid' => $vid,
            'groupid'        => $group->id,
        ]);
    }

    public function test_updates_existing_record_by_externalid(): void
    {
        $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        DB::table('volunteering')->insert([
            'externalid' => 'reach-12345',
            'title'      => 'Old Title',
            'location'   => 'Old Location',
            'description' => 'Old desc',
            'contacturl' => 'https://reachvolunteering.org.uk/vol/12345',
        ]);

        $result = $this->makeService([$this->opportunity(['title' => 'New Title'])])->sync();

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['added']);
        $this->assertDatabaseHas('volunteering', [
            'externalid' => 'reach-12345',
            'title'      => 'New Title',
        ]);
    }

    public function test_updates_existing_record_matched_by_url(): void
    {
        $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        DB::table('volunteering')->insert([
            'externalid' => 'reach-old-format-12345',
            'title'      => 'Old Title',
            'location'   => 'Old Location',
            'description' => 'Old desc',
            'contacturl' => 'https://reachvolunteering.org.uk/vol/12345',
        ]);

        $result = $this->makeService([$this->opportunity()])->sync();

        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseHas('volunteering', [
            'externalid' => 'reach-12345',
            'title'      => 'Test Volunteer Role',
        ]);
    }

    public function test_marks_deleted_when_not_in_feed(): void
    {
        DB::table('volunteering')->insert([
            'externalid' => 'reach-99999',
            'title'      => 'Stale Opportunity',
            'location'   => 'Somewhere',
            'description' => 'Old',
            'contacturl' => 'https://reachvolunteering.org.uk/vol/99999',
            'deleted'    => 0,
        ]);

        $result = $this->makeService([])->sync();

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, (int) DB::table('volunteering')->where('externalid', 'reach-99999')->value('deleted'));
    }

    public function test_skips_opportunity_without_postcode(): void
    {
        $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        $result = $this->makeService([$this->opportunity(['location' => 'London, United Kingdom'])])->sync();

        $this->assertSame(0, $result['added']);
    }

    public function test_skips_opportunity_older_than_31_days(): void
    {
        $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        $result = $this->makeService([$this->opportunity([
            'date_posted' => now()->subDays(32)->format('Y-m-d'),
        ])])->sync();

        $this->assertSame(0, $result['added']);
        // Stale record should still be tracked as seen (not deleted).
        $this->assertDatabaseMissing('volunteering', ['externalid' => 'reach-12345']);
    }

    public function test_dry_run_does_not_create_record(): void
    {
        $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        $result = $this->makeService([$this->opportunity()])->sync(dryRun: true);

        $this->assertSame(1, $result['added']);
        $this->assertDatabaseMissing('volunteering', ['externalid' => 'reach-12345']);
    }

    public function test_dry_run_does_not_delete_record(): void
    {
        DB::table('volunteering')->insert([
            'externalid' => 'reach-99999',
            'title'      => 'Stale',
            'location'   => 'Somewhere',
            'description' => 'Old',
            'contacturl' => 'https://reachvolunteering.org.uk/vol/99999',
            'deleted'    => 0,
        ]);

        $result = $this->makeService([])->sync(dryRun: true);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(0, (int) DB::table('volunteering')->where('externalid', 'reach-99999')->value('deleted'));
    }

    public function test_throws_with_diagnostic_detail_when_feed_is_not_array(): void
    {
        // Mirrors the real failure: Reach's Drupal returns a PHP fatal-error
        // page with HTTP 200 instead of JSON.
        $service = $this->makeServiceFromBody(
            "<br />\n<b>Fatal error</b>:  Maximum execution time of 30 seconds exceeded"
        );

        try {
            $service->sync();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('expected JSON array', $msg);
            // The point of the fix: triage info must be in the message.
            $this->assertStringContainsString('got NULL', $msg);
            $this->assertStringContainsString('body_length=', $msg);
            $this->assertStringContainsString('Fatal error', $msg);
        }
    }

    public function test_throws_with_diagnostic_detail_when_feed_is_empty(): void
    {
        $service = $this->makeServiceFromBody('');

        try {
            $service->sync();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('expected JSON array', $msg);
            $this->assertStringContainsString('body_length=0', $msg);
        }
    }

    public function test_does_not_delete_records_when_feed_fails(): void
    {
        // A transient feed failure must NOT cascade into deleting every Reach
        // opportunity — the throw has to happen before the deletion phase.
        DB::table('volunteering')->insert([
            'externalid'  => 'reach-77777',
            'title'       => 'Live Opportunity',
            'location'    => 'Somewhere',
            'description' => 'Active',
            'contacturl'  => 'https://reachvolunteering.org.uk/vol/77777',
            'deleted'     => 0,
        ]);

        try {
            $this->makeServiceFromBody('<br /><b>Fatal error</b>')->sync();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, (int) DB::table('volunteering')->where('externalid', 'reach-77777')->value('deleted'));
    }

    public function test_retries_and_recovers_when_a_later_fetch_succeeds(): void
    {
        $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        // First fetch returns Reach's fatal-error page; the retry returns valid JSON.
        $service = $this->makeServiceFromBodies([
            '<br /><b>Fatal error</b>: Maximum execution time of 30 seconds exceeded',
            json_encode([$this->opportunity()]),
        ]);

        $result = $service->sync();

        $this->assertSame(1, $result['added']);
        $this->assertDatabaseHas('volunteering', ['externalid' => 'reach-12345']);
    }

    public function test_skips_group_with_volunteering_disabled(): void
    {
        $canon = strtolower(preg_replace('/[^A-Za-z0-9]/', '', 'SW1A 1AA'));
        DB::table('locations')->insert([
            'name'  => 'SW1A 1AA',
            'canon' => $canon,
            'type'  => 'Postcode',
            'lat'   => 51.5007,
            'lng'   => -0.1246,
        ]);
        $this->createTestGroup([
            'lat'      => 51.5007,
            'lng'      => -0.1246,
            'publish'  => 1,
            'listable' => 1,
            'settings' => ['volunteering' => 0],
        ]);

        $result = $this->makeService([$this->opportunity()])->sync();

        $this->assertSame(0, $result['added']);
    }

    public function test_new_reach_opportunity_is_inserted_as_pending_for_moderation(): void
    {
        // Reach opportunities must require moderator approval before going live.
        // V1 inserted with pending=1; V2 must match that behaviour.
        $this->seedLocationAndGroup('SW1A 1AA', 51.5007, -0.1246);

        $this->makeService([$this->opportunity()])->sync();

        $record = DB::table('volunteering')->where('externalid', 'reach-12345')->first();
        $this->assertNotNull($record, 'Reach opportunity should be created');
        $this->assertSame(1, (int) $record->pending,
            'New Reach opportunity must be pending=1 so it appears in the ModTools approval queue, not live');
    }
}
