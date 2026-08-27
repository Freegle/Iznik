<?php

namespace Tests\Feature\Integrations;

use App\Models\Group;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRestartProjectCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
    }

    // Use Leeds coords rather than London defaults to avoid conflicts with other
    // tests that create groups at createTestGroup()'s default lat=51.5074, lng=-0.1278.
    private const TEST_LAT = 53.8008;
    private const TEST_LNG = -1.5491;

    private function makeGroup(array $override = []): Group
    {
        return $this->createTestGroup(array_merge([
            'lat'      => self::TEST_LAT,
            'lng'      => self::TEST_LNG,
            'publish'  => 1,
            'listable' => 1,
        ], $override));
    }

    private function restartGroupData(float $lat = self::TEST_LAT, float $lng = self::TEST_LNG): array
    {
        return [
            'location' => ['country_code' => 'GB', 'lat' => $lat, 'lng' => $lng],
            'name'     => 'Leeds Restart Group',
            'website'  => 'https://restarters.net/group/1',
            'email'    => 'leeds@restart.org',
        ];
    }

    private function restartEvent(int $id, string $title = 'Fix-it session'): array
    {
        return [
            'id'       => $id,
            'title'    => $title,
            'approved' => true,
            'start'    => now()->addDays(7)->format('Y-m-d H:i:s'),
            'end'      => now()->addDays(7)->addHours(3)->format('Y-m-d H:i:s'),
            'location' => 'London Community Centre, WC2H 7NA',
        ];
    }

    private function restartEventData(int $id): array
    {
        return [
            'id'          => $id,
            'description' => '<p>Come along and get your items repaired!</p>',
            'link'        => "https://restarters.net/event/$id",
            'group'       => ['name' => 'London Restart Group', 'image' => null],
        ];
    }

    private function fakeApi(int $groupId, array $groupData, array $events = [], array $eventDetails = []): void
    {
        Http::fake(function ($request) use ($groupId, $groupData, $events, $eventDetails) {
            $url = $request->url();

            if (str_contains($url, '/groups/names')) {
                return Http::response(['data' => [['id' => $groupId, 'name' => $groupData['name']]]], 200);
            }

            if (str_contains($url, "/groups/{$groupId}/events")) {
                return Http::response(['data' => $events], 200);
            }

            if (str_contains($url, "/groups/{$groupId}")) {
                return Http::response(['data' => $groupData], 200);
            }

            foreach ($eventDetails as $id => $detail) {
                if (str_contains($url, "/events/{$id}")) {
                    return Http::response(['data' => $detail], 200);
                }
            }

            return Http::response(null, 404);
        });
    }

    private function fakeEmpty(): void
    {
        Http::fake([
            '*restarters.net/api/v2/groups/names*' => Http::response(['data' => []], 200),
        ]);
    }

    public function test_community_event_service_create_works(): void
    {
        $service = app(\App\Services\CommunityEventService::class);
        $id = $service->createEvent(null, 'Test Title', 'Test Location', null, null, null, null, 'desc', 'TEST-EXT-1');
        $this->assertGreaterThan(0, $id);
        $this->assertEquals(1, DB::table('communityevents')->where('externalid', 'TEST-EXT-1')->count());
    }

    public function test_runs_cleanly_with_empty_groups(): void
    {
        $this->fakeEmpty();

        $this->artisan('integrations:sync-restartproject')
            ->expectsOutputToContain('Processed 0 new event(s), 0 updated, 0 deleted.')
            ->assertExitCode(0);
    }

    public function test_dry_run_outputs_dry_run_prefix(): void
    {
        $this->fakeEmpty();

        $this->artisan('integrations:sync-restartproject', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Would process 0 new event(s), 0 updated, 0 deleted.')
            ->assertExitCode(0);
    }

    public function test_skips_inactive_group(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/groups/names')) {
                return Http::response(['data' => [['id' => 1, 'name' => 'London [INACTIVE]']]], 200);
            }
            return Http::response(null, 404);
        });

        $this->artisan('integrations:sync-restartproject')
            ->expectsOutputToContain('Processed 0 new event(s)')
            ->assertExitCode(0);
    }

    public function test_skips_non_gb_group(): void
    {
        $this->fakeApi(2, [
            'location' => ['country_code' => 'FR', 'lat' => 48.8566, 'lng' => 2.3522],
            'name'     => 'Paris Restart',
        ]);

        $this->artisan('integrations:sync-restartproject')
            ->expectsOutputToContain('Processed 0 new event(s)')
            ->assertExitCode(0);

        $this->assertEquals(0, DB::table('communityevents')->count());
    }

    public function test_creates_new_event_for_nearby_gb_group(): void
    {
        $freegleGroup = $this->makeGroup();
        $event        = $this->restartEvent(101);

        Http::fake([
            '*api/v2/groups/names*'     => Http::response(['data' => [['id' => 10, 'name' => 'Leeds Restart']]], 200),
            '*api/v2/events/101*'       => Http::response(['data' => $this->restartEventData(101)], 200),
            '*api/v2/groups/10/events*' => Http::response(['data' => [$event]], 200),
            '*api/v2/groups/10*'        => Http::response(['data' => $this->restartGroupData()], 200),
        ]);

        // Verify group is findable
        $nearby = \App\Models\Location::groupsNear(self::TEST_LAT, self::TEST_LNG, 15);
        $this->assertContains($freegleGroup->id, $nearby, 'Group must be findable by haversine');

        // Call service directly to isolate from artisan/PendingCommand
        $service = app(\App\Services\RestartProjectService::class);
        $result  = $service->sync(false);

        $recorded = Http::recorded();
        $urls = array_map(fn($pair) => $pair[0]->url(), iterator_to_array($recorded));
        $this->assertEquals(1, $result['added'], 'Service returned wrong count. Recorded URLs: ' . json_encode($urls));
        $this->assertEquals(1, DB::table('communityevents')->where('externalid', 'Restart-101')->count());
        $this->assertEquals(1,
            DB::table('communityevents_dates')
                ->join('communityevents', 'communityevents.id', '=', 'communityevents_dates.eventid')
                ->where('communityevents.externalid', 'Restart-101')
                ->count()
        );

        $this->assertEquals(
            1,
            DB::table('communityevents_groups')
                ->join('communityevents', 'communityevents.id', '=', 'communityevents_groups.eventid')
                ->where('communityevents.externalid', 'Restart-101')
                ->where('communityevents_groups.groupid', $freegleGroup->id)
                ->count()
        );
    }

    public function test_prefixes_repair_cafe_to_title(): void
    {
        $this->makeGroup();
        $event = $this->restartEvent(102, 'Saturday Session');

        $this->fakeApi(11, $this->restartGroupData(), [$event], [102 => $this->restartEventData(102)]);

        $this->artisan('integrations:sync-restartproject')->assertExitCode(0);

        $this->assertEquals(
            'Repair Cafe: Saturday Session',
            DB::table('communityevents')->where('externalid', 'Restart-102')->value('title')
        );
    }

    public function test_does_not_prefix_repair_cafe_when_already_in_title(): void
    {
        $this->makeGroup();
        $event = $this->restartEvent(103, 'Repair Cafe Hackney');

        $this->fakeApi(12, $this->restartGroupData(), [$event], [103 => $this->restartEventData(103)]);

        $this->artisan('integrations:sync-restartproject')->assertExitCode(0);

        $this->assertEquals(
            'Repair Cafe Hackney',
            DB::table('communityevents')->where('externalid', 'Restart-103')->value('title')
        );
    }

    public function test_updates_existing_event(): void
    {
        $this->makeGroup();

        DB::table('communityevents')->insert([
            'externalid'  => 'Restart-200',
            'title'       => 'Old Title',
            'location'    => 'Old Location',
            'description' => 'Old desc',
            'pending'     => 0,
        ]);
        $existingId = (int) DB::getPdo()->lastInsertId();
        DB::table('communityevents_dates')->insert([
            'eventid' => $existingId,
            'start'   => now()->addDays(5)->format('Y-m-d H:i:s'),
            'end'     => now()->addDays(5)->addHours(2)->format('Y-m-d H:i:s'),
        ]);

        $event = $this->restartEvent(200, 'New Title');
        $this->fakeApi(20, $this->restartGroupData(), [$event], [200 => $this->restartEventData(200)]);

        $this->artisan('integrations:sync-restartproject')
            ->expectsOutputToContain('0 new event(s), 1 updated')
            ->assertExitCode(0);

        $updated = DB::table('communityevents')->where('externalid', 'Restart-200')->first();
        $this->assertEquals('Repair Cafe: New Title', $updated->title);
        $this->assertEquals(1, $updated->pending);
    }

    public function test_dry_run_does_not_create_event(): void
    {
        $this->makeGroup();
        $event = $this->restartEvent(301);

        $this->fakeApi(30, $this->restartGroupData(), [$event], [301 => $this->restartEventData(301)]);

        $this->artisan('integrations:sync-restartproject', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Would process 1 new event(s)')
            ->assertExitCode(0);

        $this->assertEquals(0, DB::table('communityevents')->where('externalid', 'Restart-301')->count());
    }

    public function test_marks_event_deleted_when_not_in_feed(): void
    {
        DB::table('communityevents')->insert([
            'externalid'  => 'Restart-999',
            'title'       => 'Old Event',
            'location'    => 'Somewhere',
            'description' => null,
            'pending'     => 0,
            'deleted'     => 0,
        ]);
        $existingId = (int) DB::getPdo()->lastInsertId();
        DB::table('communityevents_dates')->insert([
            'eventid' => $existingId,
            'start'   => now()->addDays(3)->format('Y-m-d H:i:s'),
            'end'     => now()->addDays(3)->addHours(2)->format('Y-m-d H:i:s'),
        ]);

        $this->fakeEmpty();

        $this->artisan('integrations:sync-restartproject')
            ->expectsOutputToContain('0 new event(s), 0 updated, 1 deleted.')
            ->assertExitCode(0);

        $this->assertEquals(1, DB::table('communityevents')->where('externalid', 'Restart-999')->value('deleted'));
    }

    public function test_skips_group_with_communityevents_disabled(): void
    {
        $this->makeGroup(['settings' => json_encode(['communityevents' => 0])]);

        $this->fakeApi(40, $this->restartGroupData());

        $this->artisan('integrations:sync-restartproject')
            ->expectsOutputToContain('Processed 0 new event(s)')
            ->assertExitCode(0);
    }

    /**
     * Restarters.net publishes one session under two event ids often enough that it
     * has reached moderators 24 times - a double submit in one batch, or a re-publish
     * weeks later. Both are the same real-world repair cafe, so only one should reach
     * the pending queue.
     */
    private function duplicateListing(int $id, array $of): array
    {
        return array_merge($of, ['id' => $id]);
    }

    /**
     * Http::fake() MERGES stub callbacks rather than replacing them, and the first
     * one registered wins. A test that syncs twice therefore cannot just call
     * fakeApi() again with a different feed - the second call is ignored and the
     * second sync silently re-reads the first feed. Vary the feed by reference.
     */
    private function fakeApiFeed(int $groupId, array $groupData, array &$events, array &$eventDetails): void
    {
        Http::fake(function ($request) use ($groupId, $groupData, &$events, &$eventDetails) {
            $url = $request->url();

            if (str_contains($url, '/groups/names')) {
                return Http::response(['data' => [['id' => $groupId, 'name' => $groupData['name']]]], 200);
            }

            if (str_contains($url, "/groups/{$groupId}/events")) {
                return Http::response(['data' => $events], 200);
            }

            if (str_contains($url, "/groups/{$groupId}")) {
                return Http::response(['data' => $groupData], 200);
            }

            foreach ($eventDetails as $id => $detail) {
                if (str_contains($url, "/events/{$id}")) {
                    return Http::response(['data' => $detail], 200);
                }
            }

            return Http::response(null, 404);
        });
    }

    public function test_second_listing_of_the_same_occurrence_does_not_create_a_second_event(): void
    {
        $this->makeGroup();

        $first  = $this->restartEvent(22020, 'Hackney Fixing Factory - Community Repair');
        $second = $this->duplicateListing(22021, $first);

        $this->fakeApi(50, $this->restartGroupData(), [$first, $second], [
            22020 => $this->restartEventData(22020),
            22021 => $this->restartEventData(22021),
        ]);

        $result = app(\App\Services\RestartProjectService::class)->sync(false);

        $this->assertEquals(1, $result['added']);
        $this->assertEquals(1, $result['duplicates']);
        $this->assertEquals(1, DB::table('communityevents')->where('externalid', 'Restart-22020')->count());
        $this->assertEquals(0, DB::table('communityevents')->where('externalid', 'Restart-22021')->count());
    }

    public function test_a_later_republish_of_the_same_occurrence_does_not_create_a_second_event(): void
    {
        $this->makeGroup();

        $original = $this->restartEvent(20905, 'Curborough Community Centre');
        $feed     = [$original];
        $details  = [20905 => $this->restartEventData(20905)];
        $this->fakeApiFeed(51, $this->restartGroupData(), $feed, $details);
        app(\App\Services\RestartProjectService::class)->sync(false);

        // Upstream re-publishes the same session under a new id, keeping the old one.
        $feed    = [$original, $this->duplicateListing(21268, $original)];
        $details = [
            20905 => $this->restartEventData(20905),
            21268 => $this->restartEventData(21268),
        ];
        $result = app(\App\Services\RestartProjectService::class)->sync(false);

        $rows = DB::table('communityevents')->where('externalid', 'LIKE', 'Restart-%')
            ->where('deleted', 0)->pluck('externalid')->all();
        $this->assertEquals(0, $result['added'], json_encode($result) . ' ' . json_encode($rows));
        $this->assertEquals(1, $result['duplicates'], json_encode($result) . ' ' . json_encode($rows));
        $this->assertCount(1, $rows, json_encode($rows));
    }

    public function test_keeps_the_event_when_upstream_drops_the_id_we_first_imported(): void
    {
        $this->makeGroup();

        $original = $this->restartEvent(20907, "St Joseph's RC Church");
        $feed     = [$original];
        $details  = [20907 => $this->restartEventData(20907)];
        $this->fakeApiFeed(52, $this->restartGroupData(), $feed, $details);
        app(\App\Services\RestartProjectService::class)->sync(false);

        // Only the replacement listing remains upstream. The row we hold carries the
        // old id, so without marking it seen the cleanup pass would delete it and the
        // moderators would get a fresh copy to approve all over again.
        $feed    = [$this->duplicateListing(21267, $original)];
        $details = [21267 => $this->restartEventData(21267)];
        $result  = app(\App\Services\RestartProjectService::class)->sync(false);

        $rows = DB::table('communityevents')->where('externalid', 'LIKE', 'Restart-%')
            ->pluck('externalid')->all();
        $this->assertEquals(0, $result['deleted'], json_encode($result) . ' ' . json_encode($rows));
        $this->assertEquals(1, $result['duplicates'], json_encode($result) . ' ' . json_encode($rows));
        $this->assertEquals(0, DB::table('communityevents')->where('externalid', 'Restart-20907')->value('deleted'));
    }

    public function test_a_different_session_at_the_same_venue_still_creates_its_own_event(): void
    {
        $this->makeGroup();

        $week1 = $this->restartEvent(21109, 'Hackney Fixing Factory - Community Repair');
        $week2 = array_merge($week1, [
            'id'    => 21110,
            'start' => now()->addDays(14)->format('Y-m-d H:i:s'),
            'end'   => now()->addDays(14)->addHours(3)->format('Y-m-d H:i:s'),
        ]);

        $this->fakeApi(53, $this->restartGroupData(), [$week1, $week2], [
            21109 => $this->restartEventData(21109),
            21110 => $this->restartEventData(21110),
        ]);

        $result = app(\App\Services\RestartProjectService::class)->sync(false);

        $this->assertEquals(2, $result['added']);
        $this->assertEquals(0, $result['duplicates']);
        $this->assertEquals(1, DB::table('communityevents')->where('externalid', 'Restart-21109')->count());
        $this->assertEquals(1, DB::table('communityevents')->where('externalid', 'Restart-21110')->count());
    }

    public function test_an_unreadable_start_is_never_treated_as_a_duplicate(): void
    {
        $this->makeGroup();

        // Over a thousand date rows in this table hold a zero date. If an unparseable
        // start collapsed to one moment, every event at a venue would merge into the
        // first, so a start we cannot read must never match anything.
        $events = app(\App\Services\CommunityEventService::class);
        $eid = $events->createEvent(null, 'Repair Cafe: Ghost Session', 'Nowhere', null, null, null, null, 'd', 'Restart-40001');
        DB::table('communityevents_dates')->insert([
            'eventid' => $eid,
            'start'   => '0000-00-00 00:00:00',
            'end'     => '0000-00-00 00:00:00',
        ]);

        $this->assertNull($events->findSameOccurrence('Restart-', 'Repair Cafe: Ghost Session', 'Nowhere', 'not a date'));
    }

    public function test_does_not_merge_into_an_event_a_moderator_created_by_hand(): void
    {
        $this->makeGroup();
        $event = $this->restartEvent(21499, 'Barking Fixing Factory');

        // Same occurrence, but entered manually so it has no externalid. Attaching our
        // import to it would put someone else's event under the cleanup pass's control.
        DB::table('communityevents')->insert([
            'externalid'  => null,
            'title'       => 'Repair Cafe: Barking Fixing Factory',
            'location'    => $event['location'],
            'description' => 'Typed in by a mod',
            'pending'     => 0,
        ]);
        $manualId = (int) DB::getPdo()->lastInsertId();
        DB::table('communityevents_dates')->insert([
            'eventid' => $manualId,
            'start'   => $event['start'],
            'end'     => $event['end'],
        ]);

        $this->fakeApi(54, $this->restartGroupData(), [$event], [21499 => $this->restartEventData(21499)]);

        $result = app(\App\Services\RestartProjectService::class)->sync(false);

        $this->assertEquals(1, $result['added']);
        $this->assertEquals(0, $result['duplicates']);
        $this->assertEquals(1, DB::table('communityevents')->where('externalid', 'Restart-21499')->count());
    }
}
